<?php
require 'vendor/autoload.php'; // Use GuzzleHTTP for API requests

use GuzzleHttp\Client;
use GuzzleHttp\Promise;

$promises = array();
$client = new Client();
$getID3 = new getID3;
set_time_limit(1000);
$apiKey = 'sk-proj-vrShgTCRZRkiewHxm1U4T3BlbkFJm6aWMICQ3MejTnX91qlJ';

if (!is_dir("output")) {
    mkdir("output", 0777, true);
}
if (!is_dir("./output/image")) {
    mkdir("./output/image", 0777, true);
}
if (!is_dir("./output/audio")) {
    mkdir("./output/audio", 0777, true);
}
if (!is_dir("./output/phrase")) {
    mkdir("./output/phrase", 0777, true);
}
if (!is_dir("./output/image_description")) {
    mkdir("./output/image_description", 0777, true);
}
if (!is_dir("./output/story")) {
    mkdir("./output/story", 0777, true);
}
if (!is_dir("./output/video")) {
    mkdir("./output/video", 0777, true);
}

function getAudioDuration($audioFile)
{
    // Command to get the audio duration using ffprobe
    $command = 'C:\\ffmpeg\\bin\\ffprobe.exe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($audioFile);
    exec($command, $output, $return_var);
    if ($return_var === 0) { // Success
        return (float) trim(implode("", $output));
    } else {
        return 0; // Handle the error appropriately
    }
}
function generate($imgcount, $audio_name, $image_name)
{
    $audio = "./output/audio/$audio_name";
    $output = './output/video/output.mp4';
    $output_name = date("Y-m-d-h-i-s") . "-output.mp4";
    // Get the duration of the audio file
    $audioDuration = getAudioDuration($audio);
    $segmentDuration = $audioDuration / $imgcount;
    $fileContent = "";
    // Create a temporary file listing the images and durations
    for ($i = 0; $i < $imgcount; $i++) {
        $name = $image_name[$i];
        $fileContent .= "file './output/image/$name'\nduration $segmentDuration\n";
    }
    file_put_contents('images.txt', $fileContent);

    // Create a video from the images
    $command = 'C:\\ffmpeg\\bin\\ffmpeg.exe -y -f concat -safe 0 -i images.txt -vsync vfr -pix_fmt yuv420p video.mp4';
    exec($command, $output, $return_var);
    // Check for success/failure here

    // Combine the video with the audio
    $command = "C:\\ffmpeg\\bin\\ffmpeg.exe -y -i video.mp4 -i ./output/audio/$audio_name -c:v copy -c:a aac -strict experimental ./output/video/$output_name";
    exec($command, $output, $return_var);
    // Check for success/failure here    
    unlink("images.txt");
    unlink("video.mp4");
    return $output_name;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['inputText'])) {
    $inputText = $_POST['inputText'];
    // Generate text with GPT
    $response = $client->request('POST', 'https://api.openai.com/v1/chat/completions', [
        'headers' => ['Authorization' => 'Bearer ' . $apiKey],
        'json' => ['model' => 'gpt-4o', 'messages' => [["role" => "system", "content" => "You are a helpful assistant."], ["role" => "user", "content" => $inputText]]]
    ]);
    $generatedText = json_decode($response->getBody())->choices[0]->message->content;
    $text_name = date("Y-m-d-h-i-s") . "-output.txt";
    file_put_contents("./output/phrase/$text_name", $inputText);
    file_put_contents("./output/story/$text_name", $generatedText);
    $content = $generatedText;


    // Generate voice
    $response = $client->request('POST', 'https://api.openai.com/v1/audio/speech', [
        'headers' => ['Authorization' => 'Bearer ' . $apiKey],
        'json' => ['input' => $generatedText, 'model' => 'tts-1', 'voice' => 'alloy']
    ]);
    $audioContent = $response->getBody();
    $audio_name = date("Y-m-d-h-i-s") . "-output.mp3";
    file_put_contents("./output/audio/$audio_name", $audioContent);

    $file = $getID3->analyze("./output/audio/$audio_name");

    // Accessing the file information
    $duration = $file['playtime_seconds']; // duration in seconds
    // Generate images
    $duration = (int) $duration;

    $image_name = array();
    $imageCount = $duration / 3.5; // Number of images to generate
    $imageCount = (int) $imageCount;

    $results = array();
    $video_name = "";
    $count = 0;

    $addtext = "We need to generate $imageCount images for the above story. Write a description for each image to use for dalle prompt, and each description should be sequential. plz include the tag list about each image. Don't use pronoun such as he, her, his, she, their, they, instead of this use character name. Also don't use emoticon. The start of each image description should start with ###image###";

    $imageDescription = $generatedText . $addtext;
    $response = $client->request('POST', 'https://api.openai.com/v1/chat/completions', [
        'headers' => ['Authorization' => 'Bearer ' . $apiKey],
        'json' => ['model' => 'gpt-4o', 'messages' => [["role" => "system", "content" => "You are a helpful assistant."], ["role" => "user", "content" => $imageDescription]]]
    ]);
    $generatedText = json_decode($response->getBody())->choices[0]->message->content;
    $generatedText = str_replace(["\r\n", "\r", "\n"], '', $generatedText);
    $sentences = explode("###image", $generatedText);
    for ($i = 0; $i < $imageCount; $i++) {
        $text = $sentences[$i + 1];
        $imgDes = date("Y-m-d-h-i-s") . "-image$i.txt";
        file_put_contents("./output/image_description/$imgDes", $text);
        $promises[] = $client->postAsync('https://api.openai.com/v1/images/generations', [
            'headers' => ['Authorization' => 'Bearer ' . $apiKey],
            'json' => ['model' => 'dall-e-3', 'prompt' => $text, 'n' => 1]
        ]);
        if (($i + 1) % 5 == 0 || ($i + 1) == $imageCount) {
            $results = Promise\Utils::unwrap($promises);
            foreach ($results as $response) {
                $imagename = date("Y-m-d-h-i-s") . "-image$count.jpg";
                $imageResult = json_decode($response->getBody())->data[0];
                file_put_contents("./output/image/$imagename", file_get_contents($imageResult->url));
                $image_name[] = $imagename;
                $count += 1;
            }
            if (($i + 1) != $imageCount)
                sleep(60);
            else {
                $video_name = generate($imageCount, $audio_name, $image_name);
            }
            $promises = array();
        }
    }
    // Create video with FFmpeg
    echo json_encode(['count' => $imageCount, 'finaltext' => $content, 'videoname' => $video_name, 'audioname' => $audio_name, 'image_name' => $image_name]);
    // Redirect to download or display the video
}
