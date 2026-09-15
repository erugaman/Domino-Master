<?php
// validate_image.php - Funciones para validar imágenes

/**
 * Valida una imagen en formato base64
 * @param string $base64 Imagen en formato base64
 * @param int $maxSize Tamaño máximo en bytes (default 5MB)
 * @return array Retorna ['valid' => bool, 'error' => string|null, 'data' => string|null]
 */
function validarImagenBase64($base64, $maxSize = 5242880) {
    // Verificar que no esté vacío
    if (empty($base64)) {
        return ['valid' => false, 'error' => 'No se proporcionó ninguna imagen', 'data' => null];
    }
    
    // Verificar formato base64
    if (!preg_match('/^data:image\/(\w+);base64,/', $base64, $type)) {
        return ['valid' => false, 'error' => 'Formato de imagen inválido', 'data' => null];
    }
    
    // Extraer y decodificar
    $imageData = substr($base64, strpos($base64, ',') + 1);
    $imageData = base64_decode($imageData);
    
    if ($imageData === false) {
        return ['valid' => false, 'error' => 'Los datos de la imagen están corruptos', 'data' => null];
    }
    
    // Verificar tamaño
    $size = strlen($imageData);
    if ($size > $maxSize) {
        $sizeMB = round($size / 1048576, 2);
        $maxMB = round($maxSize / 1048576, 2);
        return ['valid' => false, 'error' => "La imagen excede el tamaño máximo permitido ({$sizeMB}MB > {$maxMB}MB)", 'data' => null];
    }
    
    // Verificar tipo de imagen permitido
    $allowedTypes = ['jpeg', 'jpg', 'png', 'gif', 'webp'];
    $imageType = strtolower($type[1]);
    
    if (!in_array($imageType, $allowedTypes)) {
        return ['valid' => false, 'error' => "Tipo de imagen no permitido. Solo: " . implode(', ', $allowedTypes), 'data' => null];
    }
    
    // Verificar que sea realmente una imagen
    $imgInfo = @getimagesizefromstring($imageData);
    if ($imgInfo === false) {
        return ['valid' => false, 'error' => 'El archivo no es una imagen válida', 'data' => null];
    }
    
    return [
        'valid' => true, 
        'error' => null, 
        'data' => $base64,
        'size' => $size,
        'type' => $imageType,
        'width' => $imgInfo[0],
        'height' => $imgInfo[1]
    ];
}

/**
 * Limpia y optimiza una imagen (opcional, reduce tamaño manteniendo calidad)
 * @param string $base64 Imagen original
 * @param int $maxWidth Ancho máximo (default 400px)
 * @param int $quality Calidad JPEG (default 80)
 * @return string Imagen optimizada en base64
 */
function optimizarImagen($base64, $maxWidth = 400, $quality = 80) {
    // Esta función requiere GD library
    if (!function_exists('imagecreatefromstring')) {
        return $base64; // No se puede optimizar
    }
    
    try {
        // Decodificar imagen
        $imageData = substr($base64, strpos($base64, ',') + 1);
        $imageData = base64_decode($imageData);
        $sourceImage = @imagecreatefromstring($imageData);
        
        if (!$sourceImage) {
            return $base64;
        }
        
        // Obtener dimensiones
        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);
        
        // Redimensionar si es necesario
        if ($width > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = intval($height * ($maxWidth / $width));
            
            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Mantener transparencia para PNG
            if (strpos($base64, 'image/png') !== false) {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
                $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
                imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
            }
            
            imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($sourceImage);
            $sourceImage = $resizedImage;
        }
        
        // Convertir a JPEG para optimización
        ob_start();
        if (strpos($base64, 'image/png') !== false) {
            imagepng($sourceImage, null, 8);
        } else {
            imagejpeg($sourceImage, null, $quality);
        }
        $optimizedData = ob_get_clean();
        
        imagedestroy($sourceImage);
        
        return 'data:image/jpeg;base64,' . base64_encode($optimizedData);
        
    } catch (Exception $e) {
        error_log("Error optimizando imagen: " . $e->getMessage());
        return $base64;
    }
}
?>