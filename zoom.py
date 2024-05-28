import subprocess

# Define parameters
image_file = "image0.png"  # Path to your image file
output_file = "output.mp4"  # Path to save the output video
fps = 30  # Frames per second
image_duration = 5  # Duration of the image display in seconds
transition_duration = 5  # Duration of the zoom transition in seconds
screen_mode = "center"  # Screen mode (e.g., "center", "fit")
background_color = "black"  # Background color
zoom_start = 1.5  # Start zoom level (e.g., 1.0 for no zoom)
zoom_end = 1  # End zoom level (e.g., 1.5 for zooming in)

# Calculate total duration
total_duration = image_duration + transition_duration

# Construct the FFmpeg command
ffmpeg_command = [
    "ffmpeg",
    "-y",  # Overwrite output file if exists
    "-loop", "1",  # Loop the image
    "-i", image_file,  # Input image file
    "-vf",
    f"zoompan=z='if(lte(on,{fps * transition_duration}),"
    f"{zoom_start}+({zoom_end}-{zoom_start})*on/{fps * transition_duration},"
    f"{zoom_end})':d={fps * total_duration}:s=512*512,"
    f"format=yuv420p,setsar=1:1",
    "-t", str(total_duration),  # Total duration of the video
    "-r", str(fps),  # Frame rate
    output_file  # Output file
]

# Run the FFmpeg command using subprocess
subprocess.run(ffmpeg_command)
