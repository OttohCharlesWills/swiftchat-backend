<?php

namespace App\Services;

use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Api\Admin\AdminApi;

class CloudinaryService
{
    /**
     * Upload an image or video to Cloudinary.
     */
    public function upload($file, string $resourceType = 'auto'): array
    {
        $result = (new UploadApi())->upload(
            $file->getRealPath(),
            [
                'resource_type' => $resourceType,
                'folder' => 'swiftchat/updates',
            ]
        );

        return [
            'public_id' => $result['public_id'],
            'secure_url' => $result['secure_url'],
            'resource_type' => $result['resource_type'],
        ];
    }

    /**
     * Delete an asset from Cloudinary.
     */
    public function delete(string $publicId, string $resourceType = 'image'): void
    {
        (new UploadApi())->destroy(
            $publicId,
            [
                'resource_type' => $resourceType,
                'type' => 'upload',
                'invalidate' => true,
            ]
        );
    }
}

