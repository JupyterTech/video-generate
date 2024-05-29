<?php
require 'vendor/autoload.php'; // Use GuzzleHTTP for API requests

use GuzzleHttp\Client;
use GuzzleHttp\Promise;

// Define constants for frequently used paths

// windows
// define('FFMPEG_PATH', 'C:\\ffmpeg\\bin\\ffmpeg.exe');
// define('FFPROBE_PATH', 'C:\\ffmpeg\\bin\\ffprobe.exe');

// linux
define('FFMPEG_PATH', 'ffmpeg');
define('FFPROBE_PATH', 'ffprobe');

define('OUTPUT_DIR', './output/');
define('IMAGE_DIR', OUTPUT_DIR . 'image/');
define('AUDIO_DIR', OUTPUT_DIR . 'audio/');
define('PHRASE_DIR', OUTPUT_DIR . 'phrase/');
define('IMAGE_DESC_DIR', OUTPUT_DIR . 'image_description/');
define('STORY_DIR', OUTPUT_DIR . 'story/');
define('VIDEO_DIR', OUTPUT_DIR . 'video/');

$promises = [];
$client = new Client();
$getID3 = new getID3;
set_time_limit(1000);

// Ensure the API key is set via environment variable or command line argument
$apiKey = 'sk-proj-nDDVlCysDO0j5JpKfJ0IT3BlbkFJooUGCZ0gLWa2psNQyyUU';

// Create necessary directories
createDirectories([OUTPUT_DIR, IMAGE_DIR, AUDIO_DIR, PHRASE_DIR, IMAGE_DESC_DIR, STORY_DIR, VIDEO_DIR]);

function createDirectories($directories)
{
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}

function getAudioDuration($audioFile)
{
    $command = FFPROBE_PATH . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($audioFile);
    exec($command, $output, $return_var);
    if ($return_var === 0) {
        return (float) trim(implode("", $output));
    } else {
        return 0;
    }
}

function generate($imgcount, $audio_name, $image_name, $inputTitle)
{
    $audio = AUDIO_DIR . $audio_name;
    $output_name = $inputTitle . "-output.mp4";
    $audioDuration = getAudioDuration($audio);
    $segmentDuration = $audioDuration / $imgcount;
    $fileContent = "";

    for ($i = 0; $i < $imgcount; $i++) {
        $name = $image_name[$i];
        $fileContent .= "file '" . IMAGE_DIR . $name . "'\nduration $segmentDuration\n";
    }
    file_put_contents('images.txt', $fileContent);

    $command = FFMPEG_PATH . ' -y -f concat -safe 0 -i images.txt -vsync vfr -pix_fmt yuv420p video.mp4';
    exec($command, $output, $return_var);

    $command = FFMPEG_PATH . " -y -i video.mp4 -i $audio -c:v copy -c:a aac -strict experimental " . VIDEO_DIR . $output_name;
    exec($command, $output, $return_var);

    unlink("images.txt");
    unlink("video.mp4");
    return $output_name;
}
$isCli = php_sapi_name() === 'cli';

// Parse command line arguments
if ($isCli) {
    $options = getopt("t:i:", ["inputTitle:", "inputText:"]);

    $inputTitle = $options['t'] ?? $options['inputTitle'] ?? null;
    $inputText = $options['i'] ?? $options['inputText'] ?? null;
} else {
    // Handle web request
    $inputText = $_GET['inputText'] ?? null;
    $inputTitle = $_GET['inputTitle'] ?? null;
}
// Parse command line arguments

if ($inputText) {
    try {
        $response = $client->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $apiKey],
            'json' => [
                'model' => 'gpt-4o',
                'messages' => [
                    ["role" => "system", "content" => "You are a helpful assistant."],
                    ["role" => "user", "content" => $inputText]
                ]
            ]
        ]);
    } catch (Exception $e) {
        echo 'Failed to get response from OpenAI API: ' . $e->getMessage() . PHP_EOL;
        exit(1);
    }

    $generatedText = json_decode($response->getBody())->choices[0]->message->content;
    $text_name = $inputTitle . "-output.txt";
    file_put_contents(PHRASE_DIR . $text_name, $inputText);
    file_put_contents(STORY_DIR . $text_name, $generatedText);
    $content = $generatedText;

    try {
        $response = $client->request('POST', 'https://api.openai.com/v1/audio/speech', [
            'headers' => ['Authorization' => 'Bearer ' . $apiKey],
            'json' => ['input' => $generatedText, 'model' => 'tts-1', 'voice' => 'alloy']
        ]);
    } catch (Exception $e) {
        echo 'Failed to get audio from OpenAI API: ' . $e->getMessage() . PHP_EOL;
        exit(1);
    }

    $audioContent = $response->getBody();
    $audio_name = $inputTitle . "-output.mp3";
    file_put_contents(AUDIO_DIR . $audio_name, $audioContent);

    $file = $getID3->analyze(AUDIO_DIR . $audio_name);
    $duration = (int) $file['playtime_seconds'];
    $imageCount = (int) ($duration / 3.5);

    $image_name = [];
    $results = [];
    $video_name = "";
    $count = 0;

    $addtext = "We need to generate $imageCount images for the above story. Write a description for each image to use for dalle prompt, and each description should be sequential. Please include the tag list about each image. Don't use pronouns such as he, her, his, she, their, they, instead use the character name. Also, don't use emoticons. The start of each image description should start with ###image###.";
    $imageDescription = $generatedText . $addtext;

    try {
        $response = $client->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $apiKey],
            'json' => [
                'model' => 'gpt-4o',
                'messages' => [
                    ["role" => "system", "content" => "You are a helpful assistant."],
                    ["role" => "user", "content" => $imageDescription]
                ]
            ]
        ]);
    } catch (Exception $e) {
        echo 'Failed to get image descriptions from OpenAI API: ' . $e->getMessage() . PHP_EOL;
        exit(1);
    }

    $generatedText = json_decode($response->getBody())->choices[0]->message->content;
    $generatedText = str_replace(["\r\n", "\r", "\n"], '', $generatedText);
    $sentences = explode("###image", $generatedText);

    for ($i = 0; $i < $imageCount; $i++) {
        $text = $sentences[$i + 1];
        $imgDes = $inputTitle . "-image$i.txt";
        file_put_contents(IMAGE_DESC_DIR . $imgDes, $text);
        $promises[] = $client->postAsync('https://api.openai.com/v1/images/generations', [
            'headers' => ['Authorization' => 'Bearer ' . $apiKey],
            'json' => ['model' => 'dall-e-3', 'prompt' => $text, 'n' => 1]
        ]);

        if (($i + 1) % 5 == 0 || ($i + 1) == $imageCount) {
            $results = Promise\Utils::unwrap($promises);
            foreach ($results as $response) {
                $imagename = $inputTitle . "-image$count.jpg";
                $imageResult = json_decode($response->getBody())->data[0];
                file_put_contents(IMAGE_DIR . $imagename, file_get_contents($imageResult->url));
                $image_name[] = $imagename;
                $count += 1;
            }
            if (($i + 1) != $imageCount) {
                sleep(60);
            } else {
                $video_name = generate($imageCount, $audio_name, $image_name, $inputTitle);
            }
            $promises = [];
        }
    }

    $output = [
        'count' => $imageCount,
        'finaltext' => $content,
        'videoname' => $video_name,
        'audioname' => $audio_name,
        'image_name' => $image_name
    ];
    if ($isCli) {
        echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo json_encode($output);
    }
} else {
    if ($isCli) {
        echo "Usage: php script.php -t <inputTitle> -i <inputText>" . PHP_EOL;
    } else {
        echo "Error: 'inputText' parameter is required.";
    }
}
?>