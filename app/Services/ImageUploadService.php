<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Support\Str;

class ImageUploadService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    private const QUALITY = 85;
    
    private const THUMBNAIL_SIZES = [
        'small' => ['width' => 150, 'height' => 150],
        'medium' => ['width' => 300, 'height' => 300],
        'large' => ['width' => 600, 'height' => 400]
    ];

    public function uploadRecipeImage(UploadedFile $file, int $recipeId, string $type = 'additional'): array
    {
        $this->validateFile($file);
        
        $filename = $this->generateFilename($file);
        $directory = "recipes/{$recipeId}";
        
        // Store original image
        $path = $file->storeAs("public/{$directory}", $filename);
        $url = Storage::url($path);
        
        // Generate thumbnails
        $thumbnails = $this->generateThumbnails($file, $directory, $filename);
        
        return [
            'url' => $url,
            'path' => $path,
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'type' => $type,
            'thumbnails' => $thumbnails,
            'uploaded_at' => now()->toISOString()
        ];
    }

    public function uploadMultipleRecipeImages(array $files, int $recipeId): array
    {
        $uploadedImages = [];
        
        foreach ($files as $index => $file) {
            if ($file instanceof UploadedFile) {
                $type = $index === 0 ? 'main' : 'additional';
                $uploadedImages[] = $this->uploadRecipeImage($file, $recipeId, $type);
            }
        }
        
        return $uploadedImages;
    }

    public function deleteRecipeImage(string $path): bool
    {
        if (Storage::exists($path)) {
            // Delete thumbnails first
            $pathInfo = pathinfo($path);
            $directory = $pathInfo['dirname'];
            $filename = $pathInfo['filename'];
            $extension = $pathInfo['extension'];
            
            foreach (self::THUMBNAIL_SIZES as $size => $dimensions) {
                $thumbnailPath = "{$directory}/thumbnails/{$filename}_{$size}.{$extension}";
                Storage::delete($thumbnailPath);
            }
            
            return Storage::delete($path);
        }
        
        return false;
    }

    public function deleteRecipeImages(int $recipeId): bool
    {
        $directory = "public/recipes/{$recipeId}";
        
        if (Storage::exists($directory)) {
            return Storage::deleteDirectory($directory);
        }
        
        return false;
    }

    public function optimizeImage(UploadedFile $file, array $options = []): string
    {
        $width = $options['width'] ?? null;
        $height = $options['height'] ?? null;
        $quality = $options['quality'] ?? self::QUALITY;
        
        $image = Image::read($file);
        
        if ($width || $height) {
            $image->resize($width, $height, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }
        
        // Auto-orient based on EXIF data
        $image->orientate();
        
        // Optimize for web
        return $image->encode($file->getClientOriginalExtension(), $quality);
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Invalid file upload');
        }
        
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException('Invalid file type. Only JPEG, PNG, WebP, and GIF images are allowed.');
        }
        
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size too large. Maximum size is ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB.');
        }
        
        // Check if it's actually an image
        $imageInfo = getimagesize($file->getPathname());
        if ($imageInfo === false) {
            throw new \InvalidArgumentException('File is not a valid image.');
        }
    }

    private function generateFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        return Str::random(40) . '.' . $extension;
    }

    private function generateThumbnails(UploadedFile $file, string $directory, string $filename): array
    {
        $thumbnails = [];
        $pathInfo = pathinfo($filename);
        $name = $pathInfo['filename'];
        $extension = $pathInfo['extension'];
        
        Storage::makeDirectory("public/{$directory}/thumbnails");
        
        foreach (self::THUMBNAIL_SIZES as $size => $dimensions) {
            $thumbnailFilename = "{$name}_{$size}.{$extension}";
            $thumbnailPath = "public/{$directory}/thumbnails/{$thumbnailFilename}";
            
            $image = Image::read($file);
            $image->resize($dimensions['width'], $dimensions['height'], function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
            
            Storage::put($thumbnailPath, $image->encode($extension, self::QUALITY));
            
            $thumbnails[$size] = [
                'url' => Storage::url($thumbnailPath),
                'path' => $thumbnailPath,
                'width' => $dimensions['width'],
                'height' => $dimensions['height']
            ];
        }
        
        return $thumbnails;
    }

    public function getImageInfo(string $path): ?array
    {
        if (!Storage::exists($path)) {
            return null;
        }
        
        $fullPath = Storage::path($path);
        $imageInfo = getimagesize($fullPath);
        
        if ($imageInfo === false) {
            return null;
        }
        
        return [
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
            'mime_type' => $imageInfo['mime'],
            'size' => Storage::size($path),
            'url' => Storage::url($path)
        ];
    }

    public function processImageUpload(UploadedFile $file, array $metadata = []): array
    {
        $this->validateFile($file);
        
        return array_merge([
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'dimensions' => getimagesize($file->getPathname())
        ], $metadata);
    }
}