import os
import random
import assemblyai as aai
from moviepy.editor import *
from moviepy.video.tools.subtitles import SubtitlesClip
from moviepy.video.fx.all import resize, rotate
from moviepy.video.compositing.CompositeVideoClip import CompositeVideoClip

def load_images_from_folder(folder):
    """Load and return a sorted list of image paths from the specified folder."""
    images = [os.path.join(folder, filename) for filename in sorted(os.listdir(folder)) if os.path.isfile(os.path.join(folder, filename))]
    return images

def resize_image(image_path, target_size=(1920, 1080)):
    """Resize an image to fit within the target size while maintaining aspect ratio."""
    image = ImageClip(image_path)
    width, height = image.size
    target_width, target_height = target_size
    
    if width / height < target_width / target_height:
        new_height = int(height * target_width / width)
        new_width = target_width
    else:
        new_width = int(width * target_height / height)
        new_height = target_height
    
    resized_image = image.resize((new_width, new_height))
    return resized_image

def apply_effect(image, effect, duration=3.5):
    """Apply the specified effect to an image."""
    if effect == "zoom_in":
        return image.fx(resize, lambda t: 1 + 0.2 * t).set_duration(duration)
    elif effect == "zoom_out":
        return image.fx(resize, lambda t: 1 - 0.1 * t).set_duration(duration)
    elif effect == "shake_zoom_in_little":
        return (image.fx(resize, lambda t: 1 + 0.15 * t)
                .fx(rotate, lambda t: 3 * t if t < duration / 2 else 3 * (duration - t))
                .set_duration(duration))
    elif effect == "zoom_in_slowly":
        return image.fx(resize, lambda t: 1 + 0.05 * t).set_duration(duration)
    elif effect == "zoom_in_slowly_shake":
        return (image.fx(resize, lambda t: 1 + 0.05 * t)
                .fx(rotate, lambda t: 2 * t if t < duration / 2 else 2 * (duration - t))
                .set_duration(duration))
    elif effect == "shake_fast_zoom_in":
        return (image.fx(resize, lambda t: 1 + 0.3 * t)
                .fx(rotate, lambda t: 3 * t if t < duration / 2 else 3 * (duration - t))
                .set_duration(duration))

def add_padding(video, target_size=(1080, 1920)):
    """Add padding to the video to fit within the target size."""
    original_size = video.size
    width, height = original_size
    
    if width / height < target_size[0] / target_size[1]:
        new_width = target_size[0]
        new_height = int(height * target_size[0] / width)
    else:
        new_height = target_size[1]
        new_width = int(width * target_size[1] / height)
    
    background = ColorClip(size=target_size, color=(0, 0, 0))
    padded_video = CompositeVideoClip([video.set_position(("center", "center"))], size=target_size)
    return padded_video

def generate_subtitles(subtitle_file, video_duration, video_size, font_path, yellow_keywords=None):
    """Generate subtitles for the video from the specified subtitle file."""
    if yellow_keywords is None:
        yellow_keywords = ['Henry', 'flowers', 'each child in the village a seedling to nurture.']
    
    def generator(txt):
        color = 'yellow' if any(keyword in txt for keyword in yellow_keywords) else 'white'
        return TextClip(txt, font=font_path, fontsize=100, color=color, size=video_size, method='caption', stroke_color='black', stroke_width=10).set_position(('center', 'bottom'))
    
    subtitles = SubtitlesClip(subtitle_file, generator)
    return subtitles.set_duration(video_duration)

def transcribe_audio_to_subtitles(audio_file, api_key):
    """Transcribe the audio file and return the path to the generated subtitles."""
    aai.settings.api_key = api_key
    transcript = aai.Transcriber().transcribe(audio_file)
    subtitles = transcript.export_subtitles_srt(chars_per_caption=18)
    subtitle_file = "subtitles.srt"
    with open(subtitle_file, "w") as f:
        f.write(subtitles)
    return subtitle_file

def create_video(image_folder, audio_file, api_key, font_path, output):
    """Create a video from images, add effects, synchronize audio, and overlay subtitles."""
    subtitle_file = transcribe_audio_to_subtitles(audio_file, api_key)
    
    images = load_images_from_folder(image_folder)
    video_clips = []
    effects = ["zoom_in", "zoom_out", "shake_zoom_in_little", "zoom_in_slowly", "zoom_in_slowly_shake", "shake_fast_zoom_in"]
    page_turn_sound = AudioFileClip("./public/audio/move-sound.mp3")  # Load the page turn sound effect

    for i, img_path in enumerate(images):
        image = resize_image(img_path).set_duration(3.5)
        if i == 0:
            video_clip = apply_effect(image, "zoom_in")
        elif i == 1:
            video_clip = apply_effect(image, "zoom_out")
        else:
            effect = random.choice(effects)
            video_clip = apply_effect(image, effect)
        video_clips.append(video_clip)

    video = concatenate_videoclips(video_clips, method="compose")
    audio = AudioFileClip(audio_file)
    audio_duration = audio.duration
    
    if video.duration < audio_duration:
        last_clip = video_clips[-1].set_duration(audio_duration - sum([clip.duration for clip in video_clips[:-1]]))
        video_clips[-1] = last_clip
    
    video = concatenate_videoclips(video_clips, method="compose").set_duration(audio_duration)
    video = video.set_audio(audio)
    padded_video = add_padding(video)

    subtitles = generate_subtitles(subtitle_file, audio_duration, padded_video.size, font_path)
    final_video = CompositeVideoClip([padded_video, subtitles])

    # Add the page turn sound effect at the end of each image clip
    final_audio = CompositeAudioClip([final_video.audio])
    for i, clip in enumerate(video_clips):
        if i > 0:  # Skip the first clip
            start_time = sum([c.duration for c in video_clips[:i]])
            final_audio = CompositeAudioClip([final_audio, page_turn_sound.set_start(start_time)])
    
    final_video = final_video.set_audio(final_audio)
    
    final_video.write_videofile(output, fps=24)

# Paths to image folder, audio file, font, and output video file
image_folder = 'tim-image0/image'
audio_file = 'tim-image0/tim-output.mp3'
font_path = 'public/font/unb_pro_black.otf'  # Update this to the correct path
output_file = 'output_video_with_subtitles.mp4'
api_key = "b0e28489bdda4eb4ba9d0a60b3b0b459"  # your api_key for assemblyai

create_video(image_folder, audio_file, api_key, font_path, output_file)
