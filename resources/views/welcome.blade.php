<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Video List</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,600&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/plyr@3.6.8/dist/plyr.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/plyr@3.6.8/dist/plyr.polyfilled.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <style>
        .plyr__video-embed .plyr__poster {
            display: none;
        }
    </style>
</head>

<body class="antialiased">
    <div class="container mx-auto mt-16">
        <h1 class="text-3xl font-semibold text-center mb-8">Video List</h1>

        @if($videos->isEmpty())
        <p class="text-center">No videos found.</p>
        @else
        <div class="row">
            @foreach($videos as $video)
            <div class="col-md-4 mb-4">
                <div class="bg-white rounded-lg shadow-md p-4">
                    <video id="video_{{ $video->id }}" class="plyr" controls>
                        <source src="{{ $video->hls_url }}" type="application/x-mpegURL">
                    </video>
                    <h2 class="text-xl font-semibold mt-4">{{ $video->title }}</h2>
                    <p class="text-gray-600">{{ $video->created_at->format('d/m/Y H:i') }}</p>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            @foreach($videos as $video)
            const video_{{ $video->id }} = document.getElementById('video_{{ $video->id }}');
            if (Hls.isSupported()) {
                const hls = new Hls();
                hls.loadSource(video_{{ $video->id }}.querySelector('source').src);
                hls.attachMedia(video_{{ $video->id }});
                hls.on(Hls.Events.MANIFEST_PARSED, function(event, data) {
                    const availableQualities = hls.levels.map((l) => l.height);

                    const player = new Plyr(video_{{ $video->id }}, {
                        quality: {
                            default: availableQualities[0],
                            options: availableQualities,
                            forced: true,
                            onChange: (quality) => updateQuality(quality, hls),
                        }
                    });
                });
            } else if (video_{{ $video->id }}.canPlayType('application/vnd.apple.mpegurl')) {
                const player = new Plyr(video_{{ $video->id }});
            }
            @endforeach
        });

        function updateQuality(newQuality, hls) {
            if (newQuality === 0) {
                hls.currentLevel = -1; // -1 enables automatic quality selection
            } else {
                hls.levels.forEach((level, levelIndex) => {
                    if (level.height === newQuality) {
                        hls.currentLevel = levelIndex;
                    }
                });
            }
        }
    </script>
</body>
</html>
