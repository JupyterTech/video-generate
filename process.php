<?php
require 'vendor/autoload.php'; // Use GuzzleHTTP for API requests

use GuzzleHttp\Client;
use GuzzleHttp\Promise;

// Define constants for frequently used paths


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
set_time_limit(3000);

// Ensure the API key is set via environment variable or command line argument
$apiKey = 'sk-proj-KtLgNhLup3ou4SCE4kzHT3BlbkFJ8Dwp1eMRLEocDmSZzz7z';

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
function generate($imgcount, $audio_name, $image_name, $inputTitle)
{
    $output_name = "./output/video/".$inputTitle . "-output.mp4";
    $param1="./output/image/$inputTitle";
    $param2="./output/audio/$audio_name";
    $param3=$output_name;
    $command = escapeshellcmd("py ./python/script_video.py $param1 $param2 $param3");
    $output = [];  // Variable to capture the output
    $return_var = 0;  // Variable to capture the return status of the executed command

    // Execute the Python script
    exec($command, $output, $return_var);
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
    
    mkdir("./output/image/$inputTitle", 0777, true);
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
                $imagename = "image$count.jpg";
                $imageResult = json_decode($response->getBody())->data[0];
                file_put_contents("./output/image/$inputTitle/" . $imagename, file_get_contents($imageResult->url));
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