<?php

/**
 * Extrait une vignette d'une vidéo en utilisant FFmpeg
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

    // Si aucun timeOffset n'est spécifié, on prend 1/3 de la durée de la vidéo
    if ($timeOffset === null) {
        // Obtenir la durée de la vidéo
        $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"$videoPath\"";
        $duration = exec($command);
        $timeOffset = floor($duration / 3);
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
