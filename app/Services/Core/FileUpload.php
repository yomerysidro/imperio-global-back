<?php
namespace App\Services\Core;

use App\Models\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class FileUpload{

    public function __construct()
    {

    }

    public function upload( $input_image , $path)
    {
        $fileId = null;
        $fileModel = new File;

        $extension = $input_image->extension();
        $fileName = time().'_'.Str::random(12).'.'.$extension;

        $size = $input_image->getSize();

        $filePath = $input_image->storeAs( $path , $fileName, 'public');

        $fileModel->path = $filePath;
        $fileModel->name = $fileName;
        $fileModel->extension = $extension;
        $fileModel->size = $size;

        $fileModel->save();

        return $fileModel->id;

        return $fileId;
    }

    public function downloadFileAsBase64($fileId)
    {
        $file = File::find($fileId);

        $filePath = $file->path;
        // Verificar que el archivo existe
        if (!Storage::disk('public')->exists($filePath)) {
            return response()->json(['error' => 'Archivo no encontrado.'], 404);
        }

        // Leer el contenido binario del archivo
        $fileContents = Storage::disk('public')->get($filePath);
        $absolutePath = Storage::disk('public')->path($filePath);

        // Codificar el contenido en base64
        $base64Data = base64_encode($fileContents);

        // Obtener el tipo MIME del archivo
        $mimeType = mime_content_type($absolutePath);

        // Preparar la respuesta JSON
        $data = [
            'filename' => $file->name,
            'mimeType' => $mimeType,
            'filedata' => $base64Data,
        ];

        return $data;
    }
}
