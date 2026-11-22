<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Download WixChat</title>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #fff8f5;
            color: #241512;
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        a { text-decoration: none; }

        .navbar {
            padding: 18px 7%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f1ddd5;
        }

        .logo { font-size: 26px; font-weight: 800; color: #e85d3f; letter-spacing: -1px; }
        .logo span { color: #4b2720; }

        .back { color: #4b2720; font-weight: 700; font-size: 14px; }
        .back:hover { color: #e85d3f; }

        .btn {
            display: inline-block;
            padding: 12px 22px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            transition: 0.25s ease;
            white-space: nowrap;
        }

        .btn-primary {
            background: #e85d3f;
            color: #fff;
            box-shadow: 0 8px 20px rgba(232, 93, 63, 0.25);
        }
        .btn-primary:hover { background: #d94c30; transform: translateY(-2px); }

        .btn-small {
            padding: 9px 16px;
            background: #fff;
            border: 1px solid #e7cfc7;
            color: #4b2720;
        }
        .btn-small:hover { background: #fff1eb; }

        main {
            flex: 1;
            width: 100%;
            max-width: 820px;
            margin: 0 auto;
            padding: 60px 5% 70px;
        }

        .page-title { text-align: center; margin-bottom: 40px; }

        .page-title h1 {
            font-size: clamp(32px, 5vw, 46px);
            letter-spacing: -1.5px;
            color: #2a1713;
            margin-bottom: 10px;
        }

        .page-title p { color: #806963; font-size: 16px; }

        .section-label {
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #9a8078;
            margin: 36px 0 14px;
        }

        .latest {
            background: #fff;
            border: 2px solid #e85d3f;
            border-radius: 22px;
            padding: 28px;
            box-shadow: 0 20px 50px rgba(80, 35, 25, 0.10);
        }

        .row-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .version-name { font-size: 26px; font-weight: 800; color: #2a1713; }

        .tag {
            display: inline-block;
            font-size: 11px;
            font-weight: 800;
            background: #ffe8df;
            color: #c9472d;
            padding: 3px 10px;
            border-radius: 50px;
            margin-left: 8px;
            vertical-align: middle;
        }

        .meta { color: #9a8078; font-size: 13px; margin-top: 4px; }

        .version-row {
            background: #fff;
            border: 1px solid #f0e2dd;
            border-radius: 16px;
            padding: 18px 20px;
            margin-bottom: 12px;
        }

        .row-name { font-size: 17px; font-weight: 800; color: #2a1713; }

        .empty {
            text-align: center;
            background: #fff;
            border: 1px dashed #e7cfc7;
            border-radius: 18px;
            padding: 40px 20px;
            color: #806963;
        }

        .help {
            margin-top: 40px;
            background: #fff1eb;
            border-radius: 16px;
            padding: 20px 22px;
            font-size: 14px;
            color: #6b544e;
        }

        .help strong { color: #2a1713; }

        footer {
            padding: 22px 7%;
            background: #21110e;
            color: #b99f98;
            text-align: center;
            font-size: 13px;
        }

        footer strong { color: #e85d3f; }
    </style>
</head>

<body>

    <nav class="navbar">
        <a href="{{ url('/') }}" class="logo">WixChat<span>.</span></a>
        <a href="{{ url('/') }}" class="back">← Back to home</a>
    </nav>

    <main>

        <div class="page-title">
            <h1>Download WixChat</h1>
            <p>Tap any version to download it. We recommend the latest one.</p>
        </div>

        @if ($latest)

            <div class="section-label">Latest version</div>

            <div class="latest">
                <div class="row-top">
                    <div>
                        <div class="version-name">
                            v{{ $latest['version'] }} (build {{ $latest['build'] }})
                            <span class="tag">LATEST</span>
                        </div>
                        <div class="meta">
                            Android · {{ $latest['size'] }} · {{ $latest['date'] }}
                        </div>
                    </div>

                    <a href="{{ $latest['url'] }}" class="btn btn-primary" download>
                        ⬇ Download
                    </a>
                </div>
            </div>

            @if (count($older))
                <div class="section-label">Older versions</div>

                @foreach ($older as $v)
                    <div class="version-row">
                        <div class="row-top">
                            <div>
                                <div class="row-name">v{{ $v['version'] }} (build {{ $v['build'] }})</div>
                                <div class="meta">
                                    Android · {{ $v['size'] }} · {{ $v['date'] }}
                                </div>
                            </div>

                            <a href="{{ $v['url'] }}" class="btn btn-small" download>
                                Download
                            </a>
                        </div>
                    </div>
                @endforeach
            @endif

            <div class="help">
                <strong>Installing on Android:</strong> open the downloaded file and,
                if your phone asks, allow "Install unknown apps" for your browser.
            </div>

        @else

            <div class="empty">
                No versions are available for download yet. Please check back soon.
            </div>

        @endif

    </main>

    <footer>
        © {{ date('Y') }} <strong>WixChat</strong>. Connect. Share. Grow. 🌸
    </footer>

</body>
</html>