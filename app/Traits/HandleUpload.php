<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait HandleUpload
{
    protected function uploadOneFile(UploadedFile $file, $folder)
    {
        try {
            $fileHash = str_replace('.' . $file->extension(), '', $file->hashName());
            $fileName = $fileHash . '.' . $file->getClientOriginalExtension();
            $uploadedFile = Storage::disk('azure')->putFileAs($folder, $file, $fileName);
            $url = config('custom.azure_storage_url') . '/' . $uploadedFile;
        } catch(\Exception $e) {
            throw new \Exception('We could not able to upload this file. ' . $e->getMessage());
        }

        return $url;
    }

    protected function deleteOneFile($file, $folder)
    {
        try {
            $fileName = explode($folder.'/', $file)[1];
            $exists = Storage::disk('azure')->exists($folder.'/'.$fileName);
            if ($exists) Storage::disk('azure')->delete($folder.'/'.$fileName);
        } catch(\Exception $e) {
            // Do nothing. Simply, file does not exist.
        }
    }

}