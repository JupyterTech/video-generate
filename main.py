import os
import subprocess

# Paths to your files
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

# Calculate the duration for each image
audio_duration = get_audio_duration(audio)
segment_duration = audio_duration / 3

# Create a temporary file listing the images and durations
with open('images.txt', 'w') as f:
    f.write(f"file '{image1}'\nduration {segment_duration}\n")
    f.write(f"file '{image2}'\nduration {segment_duration}\n")
    f.write(f"file '{image3}'\nduration {segment_duration}\n")
    # Repeat the last image to ensure the total duration matches the audio
    f.write(f"file '{image3}'\n")

# Create a video from the images
subprocess.run([
    'ffmpeg', '-y', '-f', 'concat', '-safe', '0', '-i', 'images.txt',
    '-vsync', 'vfr', '-pix_fmt', 'yuv420p', 'video.mp4'
])

# Combine the video with the audio
subprocess.run([
    'ffmpeg', '-y', '-i', 'video.mp4', '-i', audio,
    '-c:v', 'copy', '-c:a', 'aac', '-strict', 'experimental', output
])

# Clean up temporary files
os.remove('images.txt')
os.remove('video.mp4')

print(f"MP4 file created: {output}")
