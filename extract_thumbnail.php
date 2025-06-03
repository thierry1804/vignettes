<?php

/**
 * Extrait une vignette d'une vidéo en utilisant FFmpeg, en privilégiant les moments avec des personnes
 * 
 * @param string $videoPath Chemin vers la vidéo source
 * @param string $outputPath Chemin où sauvegarder la vignette
 * @param int $timeOffset Moment de la vidéo où extraire la vignette (en secondes)
 * @return bool True si l'extraction a réussi, False sinon
 */
function extractThumbnail($videoPath, $outputPath, $timeOffset = null) {
    // Vérifier si FFmpeg est installé
    exec('ffmpeg -version', $output, $returnCode);
    if ($returnCode !== 0) {
        throw new Exception('FFmpeg n\'est pas installé sur ce système.');
    }

    // Vérifier si le fichier vidéo existe
    if (!file_exists($videoPath)) {
        throw new Exception('Le fichier vidéo n\'existe pas.');
    }

    // Si aucun timeOffset n'est spécifié, on cherche un moment avec des personnes
    if ($timeOffset === null) {
        // Obtenir la durée de la vidéo
        $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"$videoPath\"";
        $duration = exec($command);
        
        if (!$duration || !is_numeric($duration)) {
            throw new Exception('Impossible de déterminer la durée de la vidéo. Le fichier pourrait être corrompu.');
        }

        // Analyser la vidéo à plusieurs moments pour trouver des personnes, en évitant les génériques (début/fin)
        $bestTime = 0;
        $maxFaces = 0;
        $checkPoints = [0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9]; // 20%, 30%, 40%, 50%, 60%, 70%, 80%, 90% de la vidéo

        foreach ($checkPoints as $point) {
            $time = floor($duration * $point);
            $tempOutput = sys_get_temp_dir() . '/temp_thumb.jpg';
            
            // Extraire une image temporaire
            $command = sprintf(
                'ffmpeg -y -i "%s" -ss %d -vframes 1 -vf "scale=640:-1" "%s"',
                $videoPath,
                $time,
                $tempOutput
            );
            exec($command);

            // Utiliser FFmpeg avec le filtre de détection de visages
            $command = sprintf(
                'ffmpeg -i "%s" -vf "select=\'gt(scene,0.1)\',metadata=print:file=-" -f null -',
                $tempOutput
            );
            $output = [];
            exec($command, $output);
            
            // Compter les détections de visages
            $faceCount = count(array_filter($output, function($line) {
                return strpos($line, 'lavfi.scene_score') !== false;
            }));

            if ($faceCount > $maxFaces) {
                $maxFaces = $faceCount;
                $bestTime = $time;
            }

            // Nettoyer le fichier temporaire
            if (file_exists($tempOutput)) {
                unlink($tempOutput);
            }
        }

        $timeOffset = $bestTime;
    }

    // Supprimer le fichier de sortie s'il existe déjà
    if (file_exists($outputPath)) {
        unlink($outputPath);
    }

    // Construire la commande FFmpeg avec -y pour forcer l'écrasement et un léger filtre de luminosité
    $command = sprintf(
        'ffmpeg -y -i "%s" -ss %d -vframes 1 -vf "eq=brightness=0.10" -q:v 2 "%s"',
        $videoPath,
        $timeOffset,
        $outputPath
    );

    // Exécuter la commande
    exec($command, $output, $returnCode);

    return $returnCode === 0;
}

function extractThumbnails($videoPath, $outputDir, $numThumbnails = 8) {
    // Vérifier si FFmpeg est installé
    exec('ffmpeg -version', $output, $returnCode);
    if ($returnCode !== 0) {
        throw new Exception('FFmpeg n\'est pas installé sur ce système.');
    }

    // Vérifier si le fichier vidéo existe
    if (!file_exists($videoPath)) {
        throw new Exception('Le fichier vidéo n\'existe pas.');
    }

    // Créer le dossier de sortie s'il n'existe pas
    if (!file_exists($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    // Obtenir la durée de la vidéo
    $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"$videoPath\"";
    $duration = exec($command);
    if (!$duration || !is_numeric($duration)) {
        throw new Exception('Impossible de déterminer la durée de la vidéo. Le fichier pourrait être corrompu.');
    }

    $thumbnails = [];
    // Générer $numThumbnails vignettes entre 10% et 90% de la durée
    for ($i = 0; $i < $numThumbnails; $i++) {
        $percent = 0.1 + ($i * (0.8 / ($numThumbnails - 1))); // 0.1 à 0.9 inclus
        $timeOffset = floor($duration * $percent);
        $outputPath = rtrim($outputDir, '/\\') . '/thumb_' . ($i+1) . '.jpg';

        // Supprimer le fichier de sortie s'il existe déjà
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        // Construire la commande FFmpeg
        $command = sprintf(
            'ffmpeg -y -i "%s" -ss %d -vframes 1 -vf "eq=brightness=0.10" -q:v 2 "%s"',
            $videoPath,
            $timeOffset,
            $outputPath
        );
        exec($command, $ffmpegOutput, $returnCode);
        if ($returnCode === 0 && file_exists($outputPath)) {
            $thumbnails[] = $outputPath;
        }
    }
    return $thumbnails;
}

function extractBestThumbnail($videoPath, $outputPath, $numThumbnails = 8) {
    // Vérifier si FFmpeg est installé
    exec('ffmpeg -version', $output, $returnCode);
    if ($returnCode !== 0) {
        throw new Exception('FFmpeg n\'est pas installé sur ce système.');
    }

    // Vérifier si le fichier vidéo existe
    if (!file_exists($videoPath)) {
        throw new Exception('Le fichier vidéo n\'existe pas.');
    }

    // Obtenir la durée de la vidéo
    $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"$videoPath\"";
    $duration = exec($command);
    if (!$duration || !is_numeric($duration)) {
        throw new Exception('Impossible de déterminer la durée de la vidéo. Le fichier pourrait être corrompu.');
    }

    $bestScore = -1;
    $bestThumb = null;
    $tempThumbs = [];
    for ($i = 0; $i < $numThumbnails; $i++) {
        $percent = 0.1 + ($i * (0.8 / ($numThumbnails - 1))); // 0.1 à 0.9 inclus
        $timeOffset = floor($duration * $percent);
        $tempPath = sys_get_temp_dir() . '/thumb_' . uniqid() . '.jpg';

        // Supprimer le fichier de sortie s'il existe déjà
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        // Extraire la vignette
        $command = sprintf(
            'ffmpeg -y -i "%s" -ss %d -vframes 1 -vf "eq=brightness=0.10" -q:v 2 "%s"',
            $videoPath,
            $timeOffset,
            $tempPath
        );
        exec($command, $ffmpegOutput, $returnCode);
        if ($returnCode === 0 && file_exists($tempPath)) {
            // Calculer la variance de luminosité (pertinence)
            $score = getImageVariance($tempPath);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestThumb = $tempPath;
            }
            $tempThumbs[] = $tempPath;
        }
    }
    // Copier la meilleure vignette vers $outputPath
    if ($bestThumb) {
        copy($bestThumb, $outputPath);
    }
    // Nettoyer les vignettes temporaires
    foreach ($tempThumbs as $thumb) {
        if (file_exists($thumb)) {
            unlink($thumb);
        }
    }
    return file_exists($outputPath);
}

// Fonction utilitaire pour calculer la variance de luminosité d'une image
function getImageVariance($imagePath) {
    $im = @imagecreatefromjpeg($imagePath);
    if (!$im) return 0;
    $w = imagesx($im);
    $h = imagesy($im);
    $sum = 0;
    $sumSq = 0;
    $n = 0;
    for ($x = 0; $x < $w; $x += 10) {
        for ($y = 0; $y < $h; $y += 10) {
            $rgb = imagecolorat($im, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $lum = 0.299*$r + 0.587*$g + 0.114*$b;
            $sum += $lum;
            $sumSq += $lum * $lum;
            $n++;
        }
    }
    imagedestroy($im);
    if ($n == 0) return 0;
    $mean = $sum / $n;
    $variance = ($sumSq / $n) - ($mean * $mean);
    return $variance;
}

function extractBestThumbnailSmart($videoPath, $outputPath, $numThumbnails = 15) {
    // Vérifier si FFmpeg est installé
    exec('ffmpeg -version', $output, $returnCode);
    if ($returnCode !== 0) {
        throw new Exception('FFmpeg n\'est pas installé sur ce système.');
    }

    // Vérifier si le fichier vidéo existe
    if (!file_exists($videoPath)) {
        throw new Exception('Le fichier vidéo n\'existe pas.');
    }

    // Obtenir la durée de la vidéo
    $command = "ffprobe -v error -show_entries format=duration -of csv=p=0 \"$videoPath\"";
    $duration = floatval(trim(shell_exec($command)));
    if (!$duration || !is_numeric($duration)) {
        throw new Exception('Impossible de déterminer la durée de la vidéo. Le fichier pourrait être corrompu.');
    }

    $interval = $duration / ($numThumbnails + 1); // +1 pour éviter tout début/fin
    $bestScore = -INF;
    $bestThumb = null;
    $tempThumbs = [];

    // Créer le dossier temporaire dans le dossier courant
    $tempDir = __DIR__ . DIRECTORY_SEPARATOR . 'temp_thumbs';
    if (!file_exists($tempDir)) mkdir($tempDir, 0777, true);
    // Nettoyer le dossier temp avant de lancer
    foreach (glob($tempDir . DIRECTORY_SEPARATOR . '*.jpg') as $file) {
        @unlink($file);
    }

    for ($i = 1; $i <= $numThumbnails; $i++) {
        $timestamp = $i * $interval;
        $tempPath = $tempDir . DIRECTORY_SEPARATOR . 'thumb_' . uniqid() . '.jpg';
        // Extraire la vignette
        $cmd = sprintf(
            'ffmpeg -ss %.2f -i "%s" -vframes 1 -q:v 2 "%s" -y -loglevel error',
            $timestamp,
            $videoPath,
            $tempPath
        );
        shell_exec($cmd);
        if (file_exists($tempPath)) {
            $score = evaluateImageSmart($tempPath);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestThumb = $tempPath;
            }
            $tempThumbs[] = $tempPath;
        }
    }
    // Copier la meilleure vignette vers $outputPath
    if ($bestThumb) {
        copy($bestThumb, $outputPath);
    }
    // Nettoyer les vignettes temporaires
    foreach ($tempThumbs as $thumb) {
        if (file_exists($thumb)) {
            unlink($thumb);
        }
    }
    return file_exists($outputPath);
}

function evaluateImageSmart($imagePath) {
    $im = imagecreatefromjpeg($imagePath);
    if (!$im) return -INF;
    $w = imagesx($im);
    $h = imagesy($im);
    $sampleStep = 4;
    $brightness = 0;
    $sharpness = 0;
    $darkPixels = 0;
    $brightPixels = 0;
    $total = 0;
    for ($y = 1; $y < $h - 1; $y += $sampleStep) {
        for ($x = 1; $x < $w - 1; $x += $sampleStep) {
            $gray = brightnessAt($im, $x, $y);
            $brightness += $gray;
            $total++;
            if ($gray < 30) $darkPixels++;
            if ($gray > 230) $brightPixels++;
            // Netteté (laplacien simplifié)
            $c = $gray;
            $cx = brightnessAt($im, $x - 1, $y) + brightnessAt($im, $x + 1, $y);
            $cy = brightnessAt($im, $x, $y - 1) + brightnessAt($im, $x, $y + 1);
            $lap = abs(4 * $c - $cx - $cy);
            $sharpness += $lap;
        }
    }
    imagedestroy($im);
    if ($total == 0) return -INF;
    $brightnessAvg = $brightness / $total;
    $sharpnessAvg = $sharpness / $total;
    $darkRatio = $darkPixels / $total;
    $brightRatio = $brightPixels / $total;
    // Normalisation basique
    $b = $brightnessAvg / 255;
    // On normalise la netteté sur une plage plus large (max 200)
    $s = min($sharpnessAvg / 200, 1.0);
    // Éliminer les images trop floues
    if ($sharpnessAvg < 1) return -INF;
    // Score composite (netteté très prioritaire)
    return (0.3 * $b) + (0.6 * $s) - (0.05 * $darkRatio) - (0.05 * $brightRatio);
}

function brightnessAt($im, $x, $y) {
    $rgb = imagecolorat($im, $x, $y);
    $r = ($rgb >> 16) & 0xFF;
    $g = ($rgb >> 8) & 0xFF;
    $b = $rgb & 0xFF;
    return 0.299*$r + 0.587*$g + 0.114*$b;
}
