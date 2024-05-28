import os
import subprocess

# Absolute paths to your files
image1 = 'image0.png'
image2 = 'image1.png'
image3 = 'image2.png'
audio = 'output.mp3'
output = 'output.mp4'

# Get the duration of the audio file
def get_audio_duration(audio_file):
    result = subprocess.run(
        ['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', audio_file],
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT
    )
    return float(result.stdout)

try:
    # Calculate the duration for each image
    audio_duration = get_audio_duration(audio)
    segment_duration = audio_duration / 3

    def create_animation(input_image, output_video, scale_factor=2, rotation_speed=0.001, duration=5):
        """
        Create an animated MP4 video with scale and rotation effects using FFmpeg.

        :param input_image: Path to the input image.
        :param output_video: Path to the output video file.
        :param scale_factor: Factor by which to scale the image.
        :param rotation_speed: Speed of rotation in radians per second (PI/4 = 0.25).
        :param duration: Duration of the output video in seconds.
        """
        scale_str = f"scale=iw*{scale_factor}:ih*{scale_factor}"
        # rotate_str = f"rotate={rotation_speed}*PI*t"
        rotate_str = f""

        command = [
            'ffmpeg', '-loop', '1', '-i', input_image, '-vf',
            f"{scale_str},{rotate_str}", '-t', str(duration), '-pix_fmt', 'yuv420p', output_video
        ]
        
        subprocess.run(command, check=True)

    # Generate scaled video segments for each image
    create_animation(image1, 'image1.mp4', 1.5, 45)
    create_animation(image2, 'image2.mp4', 1.5, 45)
    create_animation(image3, 'image3.mp4', 1.5, 45)

    # Concatenate the video segments
    with open('videos.txt', 'w') as f:
        f.write(f"file 'image1.mp4'\n")
        f.write(f"file 'image2.mp4'\n")
        f.write(f"file 'image3.mp4'\n")

    subprocess.run([
        'ffmpeg', '-y', '-f', 'concat', '-safe', '0', '-i', 'videos.txt',
        '-c', 'copy', 'video.mp4'
    ])

    # Combine the concatenated video with the audio
    subprocess.run([
        'ffmpeg', '-y', '-i', 'video.mp4', '-i', audio,
        '-c:v', 'copy', '-c:a', 'aac', '-strict', 'experimental', '-shortest', output
    ])

    # Clean up temporary files
    # os.remove('image1.mp4')
    # os.remove('image2.mp4')
    # os.remove('image3.mp4')
    # os.remove('videos.txt')
    # os.remove('video.mp4')

    print(f"MP4 file created: {output}")

except Exception as e:
    print(f"An error occurred: {e}")
