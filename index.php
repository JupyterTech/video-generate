<?php

// Paths to your files
$image1 = 'image0.png';
$image2 = 'image1.png';
$image3 = 'image2.png';
$audio = 'output.mp3';
$output = 'output.mp4';

// Function to get the duration of the audio file
function getAudioDuration($audioFile) {
    $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($audioFile);
    $result = shell_exec($command);
    return floatval($result);
}

// Calculate the duration for each image
$audioDuration = getAudioDuration($audio);
$segmentDuration = $audioDuration / 3;

// Create a temporary file listing the images and durations
$imagesTxt = "images.txt";
$handle = fopen($imagesTxt, 'w');
fwrite($handle, "file '$image1'\nduration $segmentDuration\n");
fwrite($handle, "file '$image2'\nduration $segmentDuration\n");
fwrite($handle, "file '$image3'\nduration $segmentDuration\n");
// Repeat the last image to ensure the total duration matches the audio
fwrite($handle, "file '$image3'\n");
fclose($handle);

// Create a video from the images
$command = "ffmpeg -y -f concat -safe 0 -i $imagesTxt -vsync vfr -pix_fmt yuv420p video.mp4";
shell_exec($command);

// Combine the video with the audio
$command = "ffmpeg -y -i video.mp4 -i $audio -c:v copy -c:a aac -strict experimental $output";
shell_exec($command);

// Clean up temporary files
unlink($imagesTxt);
unlink('video.mp4');

echo "MP4 file created: $output\n";
?>
