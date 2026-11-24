<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Pack;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RegisterStarterKit extends Command
{
    protected $signature = 'packs:register-starter {image : Path to the original Kit de Inicio image}';

    protected $description = 'Register the starter kit and its original image';

    public function handle(): int
    {
        $source = $this->argument('image');
        $bytes = is_file($source) ? file_get_contents($source) : false;
        $extension = match ($bytes ? (@getimagesizefromstring($bytes)[2] ?? null) : null) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            default => null,
        };
        if (!$extension) {
            $this->error('Provide the original kit image (PNG or JPEG).');
            return self::FAILURE;
        }

        $path = 'files/packs/kit-inicio-'.hash('sha256', $bytes).'.'.$extension;
        $disk = Storage::disk('public');
        $lock = 'imperio:starter-kit:'.md5(DB::getDatabaseName());

        if ((int) DB::selectOne('SELECT GET_LOCK(?, 15) AS acquired', [$lock], false)->acquired !== 1) {
            $this->error('Unable to acquire the registration lock.');
            return self::FAILURE;
        }

        try {
            $pack = DB::transaction(function () use ($bytes, $path, $disk, $extension, $source) {
                $matches = Pack::with('file')->where('title', Pack::STARTER_TITLE)->get();
                if ($matches->isNotEmpty()) {
                    $pack = $matches->first();
                    if ($matches->count() !== 1 || $pack->category !== 'Producto'
                        || (float) $pack->price !== 50.0 || (float) $pack->points !== 0.0
                        || (float) $pack->discount !== 35.0 || !(bool) $pack->state
                        || $pack->file?->path !== $path) {
                        throw new RuntimeException('Existing kit differs from the expected registration.');
                    }
                    if (!$disk->exists($path) && !$disk->put($path, $bytes)) {
                        throw new RuntimeException('Unable to restore the kit image.');
                    }
                    return $pack;
                }

                if (!$disk->exists($path) && !$disk->put($path, $bytes)) {
                    throw new RuntimeException('Unable to store the kit image.');
                }

                $file = File::create([
                    'path' => $path,
                    'name' => basename($source),
                    'extension' => $extension,
                    'size' => strlen($bytes),
                ]);

                return Pack::create([
                    'title' => Pack::STARTER_TITLE,
                    'category' => 'Producto',
                    'price' => 50,
                    'points' => 0,
                    'discount' => 35,
                    'state' => true,
                    'image' => $file->id,
                ]);
            });
            $this->info($pack->id);
            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?)', [$lock], false);
        }
    }
}
