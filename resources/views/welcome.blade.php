<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WixChat — Connect. Share. Grow.</title>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #fff8f5;
            color: #241512;
            line-height: 1.6;
            overflow-x: hidden;
        }

        a { text-decoration: none; }

        section[id] { scroll-margin-top: 80px; }

        /* ---------- NAVBAR ---------- */
        .navbar {
            position: fixed;
            top: 0; left: 0;
            width: 100%;
            z-index: 1000;
            padding: 16px 7%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255, 248, 245, 0.93);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid #f1ddd5;
        }

        .logo { font-size: 26px; font-weight: 800; color: #e85d3f; letter-spacing: -1px; }
        .logo span { color: #4b2720; }

        .nav-links { display: flex; gap: 26px; }
        .nav-links a { color: #6b544e; font-size: 14px; font-weight: 700; }
        .nav-links a:hover { color: #e85d3f; }

        .nav-actions { display: flex; align-items: center; gap: 10px; }

        .btn {
            display: inline-block;
            padding: 11px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            transition: 0.25s ease;
            border: none;
            cursor: pointer;
            font-family: inherit;
        }

        .btn-ghost { color: #4b2720; background: transparent; }
        .btn-ghost:hover { background: #f5e5df; }

        .btn-primary {
            background: #e85d3f;
            color: #fff;
            box-shadow: 0 8px 20px rgba(232, 93, 63, 0.25);
        }
        .btn-primary:hover { background: #d94c30; transform: translateY(-2px); }

        .btn-outline { border: 1px solid #e7cfc7; background: #fff; color: #4b2720; }
        .btn-outline:hover { background: #fff1eb; }

        .btn-lg { padding: 15px 26px; font-size: 15px; border-radius: 12px; }

        /* ---------- HERO ---------- */
        .hero {
            min-height: 100vh;
            padding: 140px 7% 80px;
            display: flex;
            align-items: center;
            background:
                radial-gradient(circle at top left, rgba(255, 190, 160, 0.35), transparent 38%),
                radial-gradient(circle at bottom right, rgba(232, 93, 63, 0.15), transparent 30%),
                #fff8f5;
        }

        .hero-content {
            max-width: 1200px;
            width: 100%;
            margin: auto;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            align-items: center;
            gap: 60px;
        }

        .badge {
            display: inline-block;
            background: #ffe8df;
            color: #c9472d;
            padding: 8px 14px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 22px;
        }

        .hero h1 {
            font-size: clamp(42px, 6vw, 74px);
            line-height: 1.05;
            letter-spacing: -3px;
            margin-bottom: 22px;
            color: #2a1713;
        }

        .hero h1 span { color: #e85d3f; }

        .hero p.lead {
            max-width: 560px;
            font-size: 18px;
            color: #745c56;
            margin-bottom: 30px;
        }

        .hero-buttons { display: flex; gap: 14px; flex-wrap: wrap; }

        .hero-note { margin-top: 18px; font-size: 13px; color: #9a8078; }

        /* chat mock */
        .hero-visual {
            position: relative;
            display: flex;
            justify-content: center;
            min-height: 460px;
            align-items: center;
        }

        .phone {
            width: 100%;
            max-width: 330px;
            background: #fff;
            border-radius: 30px;
            border: 1px solid #f2e1db;
            box-shadow: 0 30px 80px rgba(80, 35, 25, 0.18);
            padding: 18px;
            transform: rotate(2deg);
        }

        .phone-top {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f2e9e6;
        }

        .avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #e85d3f, #ffad7d);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: bold;
        }

        .phone-top h4 { font-size: 14px; color: #2a1713; }
        .phone-top small { color: #3aa86b; font-size: 12px; }

        .chat { padding: 18px 0 6px; display: flex; flex-direction: column; gap: 10px; }

        .bubble {
            max-width: 78%;
            padding: 10px 14px;
            border-radius: 16px;
            font-size: 13px;
        }

        .bubble.in { background: #f7ece8; color: #4b2720; border-bottom-left-radius: 4px; }
        .bubble.out { background: #e85d3f; color: #fff; align-self: flex-end; border-bottom-right-radius: 4px; }

        .floating {
            position: absolute;
            background: #fff;
            padding: 14px 18px;
            border-radius: 16px;
            border: 1px solid #f2e1db;
            box-shadow: 0 15px 35px rgba(80, 35, 25, 0.14);
        }

        .floating strong { display: block; font-size: 14px; color: #2a1713; }
        .floating span { font-size: 12px; color: #9a8078; }
        .floating.one { left: -10px; bottom: 50px; }
        .floating.two { right: -15px; top: 45px; }

        /* ---------- STATS ---------- */
        .stats {
            background: #2d1814;
            padding: 38px 7%;
        }

        .stats-grid {
            max-width: 1000px;
            margin: auto;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            text-align: center;
        }

        .stats-grid strong { display: block; font-size: 30px; color: #fff; letter-spacing: -1px; }
        .stats-grid span { color: #d8c3bd; font-size: 13px; }

        /* ---------- SECTIONS ---------- */
        .section { padding: 100px 7%; }
        .section.white { background: #fff; }

        .section-header { text-align: center; max-width: 660px; margin: 0 auto 55px; }

        .section-header .eyebrow {
            color: #e85d3f;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .section-header h2 {
            font-size: clamp(30px, 4vw, 46px);
            letter-spacing: -1.5px;
            color: #2a1713;
            margin-bottom: 14px;
            line-height: 1.15;
        }

        .section-header p { color: #806963; font-size: 16px; }

        /* features */
        .feature-grid {
            max-width: 1200px;
            margin: auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 22px;
        }

        .feature-card {
            padding: 30px;
            border-radius: 20px;
            border: 1px solid #f0e2dd;
            background: #fffdfc;
            transition: 0.25s ease;
        }

        .feature-card:hover { transform: translateY(-7px); box-shadow: 0 20px 40px rgba(80, 35, 25, 0.08); }

        .feature-icon {
            width: 52px; height: 52px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 15px;
            background: #ffe8df;
            font-size: 25px;
            margin-bottom: 18px;
        }

        .feature-card h3 { font-size: 19px; color: #2a1713; margin-bottom: 8px; }
        .feature-card p { font-size: 14px; color: #806963; }

        /* steps */
        .steps {
            max-width: 1000px;
            margin: auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
        }

        .step { text-align: center; padding: 10px 14px; }

        .step-num {
            width: 54px; height: 54px;
            margin: 0 auto 18px;
            border-radius: 50%;
            background: #e85d3f;
            color: #fff;
            font-size: 22px;
            font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 10px 22px rgba(232, 93, 63, 0.28);
        }

        .step h3 { font-size: 19px; color: #2a1713; margin-bottom: 8px; }
        .step p { font-size: 14px; color: #806963; }

        /* download cta */
        .cta {
            padding: 90px 7%;
            background: #2d1814;
            text-align: center;
        }

        .cta h2 {
            color: #fff;
            font-size: clamp(32px, 5vw, 54px);
            letter-spacing: -2px;
            margin-bottom: 16px;
            line-height: 1.1;
        }

        .cta p { max-width: 580px; margin: 0 auto 30px; color: #d8c3bd; font-size: 17px; }

        /* faq */
        .faq-list { max-width: 780px; margin: auto; }

        .faq-list details {
            background: #fff;
            border: 1px solid #f0e2dd;
            border-radius: 16px;
            margin-bottom: 12px;
            overflow: hidden;
        }

        .faq-list summary {
            list-style: none;
            cursor: pointer;
            padding: 20px 24px;
            font-weight: 800;
            font-size: 16px;
            color: #2a1713;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }

        .faq-list summary::-webkit-details-marker { display: none; }

        .faq-list summary::after {
            content: "+";
            font-size: 24px;
            color: #e85d3f;
            font-weight: 400;
            flex-shrink: 0;
        }

        .faq-list details[open] summary::after { content: "−"; }
        .faq-list details[open] { border-color: #e85d3f; }

        .faq-list details p { padding: 0 24px 22px; color: #806963; font-size: 15px; }

        /* complaint */
        .complaint-wrap {
            max-width: 680px;
            margin: auto;
            background: #fff;
            border: 1px solid #f0e2dd;
            border-radius: 24px;
            padding: 36px;
            box-shadow: 0 20px 50px rgba(80, 35, 25, 0.08);
        }

        .field { margin-bottom: 20px; }

        .field label {
            display: block;
            font-size: 14px;
            font-weight: 800;
            color: #2a1713;
            margin-bottom: 7px;
        }

        .field label small { font-weight: 400; color: #9a8078; }

        .field input[type="text"],
        .field input[type="email"],
        .field textarea {
            width: 100%;
            padding: 13px 15px;
            border: 1px solid #e7cfc7;
            border-radius: 12px;
            font-size: 15px;
            font-family: inherit;
            background: #fffdfc;
            color: #241512;
            outline: none;
            transition: 0.2s ease;
        }

        .field input:focus,
        .field textarea:focus { border-color: #e85d3f; box-shadow: 0 0 0 3px rgba(232, 93, 63, 0.12); }

        .field textarea { min-height: 150px; resize: vertical; }

        .field input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 1px dashed #e7cfc7;
            border-radius: 12px;
            background: #fffdfc;
            font-size: 14px;
        }

        .hint { font-size: 12px; color: #9a8078; margin-top: 6px; }

        .error { color: #c0392b; font-size: 13px; margin-top: 6px; }

        .alert-success {
            background: #e8f7ee;
            border: 1px solid #b8e3c8;
            color: #22663f;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 22px;
            font-size: 14px;
            font-weight: 700;
        }

        .hp { position: absolute; left: -9999px; height: 0; overflow: hidden; }

        .complaint-wrap .btn-primary { width: 100%; padding: 15px; font-size: 16px; }

        /* footer */
        footer { padding: 50px 7% 28px; background: #21110e; color: #b99f98; }

        .footer-top {
            max-width: 1100px;
            margin: auto;
            display: flex;
            justify-content: space-between;
            gap: 30px;
            flex-wrap: wrap;
            padding-bottom: 28px;
            border-bottom: 1px solid #3a2420;
        }

        .footer-brand .logo { display: inline-block; margin-bottom: 8px; }
        .footer-brand p { font-size: 13px; max-width: 280px; }

        .footer-links { display: flex; gap: 22px; flex-wrap: wrap; align-items: flex-start; }
        .footer-links a { color: #b99f98; font-size: 14px; }
        .footer-links a:hover { color: #fff; }

        .footer-bottom { text-align: center; font-size: 13px; padding-top: 22px; }
        .footer-bottom strong { color: #e85d3f; }

        /* ---------- RESPONSIVE ---------- */
        @media (max-width: 900px) {
            .nav-links { display: none; }
            .navbar { padding: 14px 5%; }

            .hero { padding: 120px 5% 70px; }
            .hero-content { grid-template-columns: 1fr; gap: 40px; text-align: center; }
            .hero p.lead { margin-left: auto; margin-right: auto; }
            .hero-buttons { justify-content: center; }
            .hero-visual { min-height: 430px; }

            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 28px; }

            .section { padding: 75px 5%; }
            .feature-grid { grid-template-columns: repeat(2, 1fr); }
            .steps { grid-template-columns: 1fr; }
        }

        @media (max-width: 560px) {
            .logo { font-size: 22px; }
            .btn { padding: 9px 13px; font-size: 12px; }
            .hero h1 { letter-spacing: -2px; }
            .hero p.lead { font-size: 16px; }
            .phone { transform: none; }
            .floating.one { left: 0; bottom: 10px; }
            .floating.two { right: 0; top: 5px; }
            .feature-grid { grid-template-columns: 1fr; }
            .complaint-wrap { padding: 24px 20px; }
            .footer-top { flex-direction: column; }
        }
    </style>
</head>

<body>

    {{-- NAVBAR --}}
    <nav class="navbar">
        <a href="{{ url('/') }}" class="logo">WixChat<span>.</span></a>

        <div class="nav-links">
            <a href="#features">Features</a>
            <a href="#how">How it works</a>
            <a href="#faq">FAQs</a>
            <a href="#complaint">Complaints</a>
        </div>

        <div class="nav-actions">
            <a href="{{ route('download') }}" class="btn btn-ghost">Download</a>

            @if (Route::has('login'))
                @auth
                    <a href="{{ url('/home') }}" class="btn btn-primary">Go to Feed</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-ghost">Log in</a>
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="btn btn-primary">Join WixChat</a>
                    @endif
                @endauth
            @endif
        </div>
    </nav>


    {{-- HERO --}}
    <section class="hero">
        <div class="hero-content">

            <div>
                <div class="badge">🌸 Your world. Your people.</div>

                <h1>Where people <span>connect</span> and moments bloom.</h1>

                <p class="lead">
                    WixChat is your space to chat, make group conversations, share
                    moments and stay close to the people who matter, all in one
                    simple app.
                </p>

                <div class="hero-buttons">
                    <a href="{{ route('download') }}" class="btn btn-primary btn-lg">⬇ Download the app</a>

                    @guest
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}" class="btn btn-outline btn-lg">Log in on the web</a>
                        @endif
                    @else
                        <a href="{{ url('/home') }}" class="btn btn-outline btn-lg">Go to your feed</a>
                    @endguest
                </div>

                <p class="hero-note">Free to download · Available for Android</p>
            </div>

            <div class="hero-visual">
                <div class="phone">
                    <div class="phone-top">
                        <div class="avatar">W</div>
                        <div>
                            <h4>Weekend Squad 🌸</h4>
                            <small>● 5 online</small>
                        </div>
                    </div>

                    <div class="chat">
                        <div class="bubble in">Are we still on for Saturday? 👀</div>
                        <div class="bubble out">Yes! Sending the group invite link now.</div>
                        <div class="bubble in">Got it. Joined! 🎉</div>
                        <div class="bubble out">See you all there 🌍</div>
                    </div>
                </div>

                <div class="floating one">
                    <strong>👥 Group chats</strong>
                    <span>Invite anyone with a link.</span>
                </div>

                <div class="floating two">
                    <strong>🔒 App lock</strong>
                    <span>Fingerprint, face or PIN.</span>
                </div>
            </div>

        </div>
    </section>


    {{-- STATS --}}
    <section class="stats">
        <div class="stats-grid">
            <div><strong>Free</strong><span>to download and use</span></div>
            <div><strong>1-to-1</strong><span>and group conversations</span></div>
            <div><strong>Voice</strong><span>and video calls</span></div>
            <div><strong>Your style</strong><span>themes and wallpapers</span></div>
        </div>
    </section>


    {{-- FEATURES --}}
    <section class="section white" id="features">

        <div class="section-header">
            <div class="eyebrow">Features</div>
            <h2>Everything you need to stay connected.</h2>
            <p>
                WixChat is built to feel simple and personal, with the tools you
                actually use every day and none of the clutter.
            </p>
        </div>

        <div class="feature-grid">

            <div class="feature-card">
                <div class="feature-icon">💬</div>
                <h3>Fast, simple chats</h3>
                <p>Message friends and family one-on-one, with full chat history you can scroll back through and search.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">👥</div>
                <h3>Group chats</h3>
                <p>Create a group, add people or share an invite link, and chat together. You choose who can add you to groups.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">📞</div>
                <h3>Voice and video calls</h3>
                <p>Call the people you chat with straight from the app, without switching to anything else.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">🎨</div>
                <h3>Make it yours</h3>
                <p>Dark, light or system mode, plus custom chat wallpapers, bubble colors and font size that stay on your phone.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">🔒</div>
                <h3>App lock</h3>
                <p>Protect your chats with your fingerprint, face or phone PIN. You choose how long the app stays unlocked.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">↗️</div>
                <h3>Forward and share</h3>
                <p>Forward messages to several chats at once and share your moments through statuses.</p>
            </div>

        </div>
    </section>


    {{-- HOW IT WORKS --}}
    <section class="section" id="how">

        <div class="section-header">
            <div class="eyebrow">How it works</div>
            <h2>Up and running in three steps.</h2>
            <p>No long setup. Download, sign up and start talking.</p>
        </div>

        <div class="steps">

            <div class="step">
                <div class="step-num">1</div>
                <h3>Download the app</h3>
                <p>Head to the download page and pick the latest version for your Android phone.</p>
            </div>

            <div class="step">
                <div class="step-num">2</div>
                <h3>Create your account</h3>
                <p>Sign up in a minute and set up your profile so people can recognise you.</p>
            </div>

            <div class="step">
                <div class="step-num">3</div>
                <h3>Start chatting</h3>
                <p>Message your contacts, make a group and send invite links to bring everyone in.</p>
            </div>

        </div>
    </section>


    {{-- DOWNLOAD CTA --}}
    <section class="cta">
        <h2>Your next conversation starts here.</h2>
        <p>
            Get the latest WixChat for Android. Older versions are available too
            on the download page.
        </p>
        <a href="{{ route('download') }}" class="btn btn-primary btn-lg">⬇ Go to downloads</a>
    </section>


    {{-- FAQ --}}
    <section class="section white" id="faq">

        <div class="section-header">
            <div class="eyebrow">FAQs</div>
            <h2>Questions people often ask.</h2>
            <p>Can't find your answer? Send us a message using the form below.</p>
        </div>

        <div class="faq-list">

            <details>
                <summary>Is WixChat free?</summary>
                <p>Yes. You can download and use WixChat for free.</p>
            </details>

            <details>
                <summary>Which devices does it support?</summary>
                <p>WixChat is currently available for Android phones. You can download it from the download page on this website.</p>
            </details>

            <details>
                <summary>How do I install the app?</summary>
                <p>Open the downloaded file on your phone. If Android asks, allow "Install unknown apps" for your browser, then tap Install. You can switch that permission off again afterwards.</p>
            </details>

            <details>
                <summary>Which version should I download?</summary>
                <p>Always pick the latest version, as it has the newest features and fixes. Older versions stay on the download page in case you need one.</p>
            </details>

            <details>
                <summary>How do group chats and invite links work?</summary>
                <p>Anyone can create a group, add people and share an invite link so others can join. If someone has turned off "allow others to add me to groups" in their privacy settings, they won't be added automatically. They will receive the invite link in their chat instead.</p>
            </details>

            <details>
                <summary>Can I lock the app?</summary>
                <p>Yes. Turn on App lock in settings and unlock with your fingerprint, face or your phone's PIN or password. You can choose how long the app stays unlocked after you leave it.</p>
            </details>

            <details>
                <summary>Are my chat wallpapers uploaded anywhere?</summary>
                <p>No. Chat and chat-list wallpapers are kept only on your phone.</p>
            </details>

            <details>
                <summary>How do I report a problem or make a complaint?</summary>
                <p>Use the complaint form on this page. Add your name, email and a description, and attach a screenshot if it helps.</p>
            </details>

        </div>
    </section>


    {{-- COMPLAINT FORM --}}
    <section class="section" id="complaint">

        <div class="section-header">
            <div class="eyebrow">Contact us</div>
            <h2>File a complaint.</h2>
            <p>
                Something not working, or someone misusing WixChat? Tell us what
                happened and we will look into it.
            </p>
        </div>

        <div class="complaint-wrap">

            @if (session('success'))
                <div class="alert-success">{{ session('success') }}</div>
            @endif

            <form method="POST" action="{{ route('complaints.store') }}" enctype="multipart/form-data">
                @csrf

                {{-- spam trap: real users never see or fill this --}}
                <div class="hp" aria-hidden="true">
                    <label>Leave this empty</label>
                    <input type="text" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="field">
                    <label for="name">Your name</label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" placeholder="Enter your full name" required>
                    @error('name') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="you@example.com" required>
                    @error('email') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="message">Your complaint</label>
                    <textarea id="message" name="message" placeholder="Describe what happened..." required>{{ old('message') }}</textarea>
                    @error('message') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="attachment">Attach a file <small>(optional)</small></label>
                    <input type="file" id="attachment" name="attachment" accept=".jpg,.jpeg,.png,.pdf">
                    <div class="hint">A screenshot or PDF. JPG, PNG or PDF, up to 5 MB.</div>
                    @error('attachment') <div class="error">{{ $message }}</div> @enderror
                </div>

                <button type="submit" class="btn btn-primary">Submit complaint</button>
            </form>

        </div>
    </section>


    {{-- FOOTER --}}
    <footer>
        <div class="footer-top">
            <div class="footer-brand">
                <a href="{{ url('/') }}" class="logo">WixChat<span style="color:#fff">.</span></a>
                <p>Connect. Share. Grow. A simple place to chat with the people who matter.</p>
            </div>

            <div class="footer-links">
                <a href="#features">Features</a>
                <a href="#how">How it works</a>
                <a href="{{ route('download') }}">Download</a>
                <a href="#faq">FAQs</a>
                <a href="#complaint">Complaints</a>
            </div>
        </div>

        <div class="footer-bottom">
            © {{ date('Y') }} <strong>WixChat</strong>. Connect. Share. Grow. 🌸
        </div>
    </footer>

</body>
</html>