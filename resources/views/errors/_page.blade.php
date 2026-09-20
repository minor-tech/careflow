{{--
    The shared look of the error pages. Deliberately self-contained (its own
    inline styles, no build assets, no database, no session, no signed-in user):
    an error page that itself needs those things can fail exactly when it is
    needed most. The colours are the CareFlow tokens.
--}}
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>{{ $code }} · CareFlow</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap" rel="stylesheet">
        <style>
            :root { --bg: #FFFFFF; --primary: #1E6B5C; --text: #0B2E29; --border: #EEF1F0; }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100vh;
                background: linear-gradient(to bottom, #CFE0D9, var(--bg) 60%);
                color: var(--text);
                font-family: "Google Sans", -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                font-optical-sizing: auto;
                font-variation-settings: "GRAD" 0;
                -webkit-font-smoothing: antialiased;
            }
            main {
                display: flex;
                min-height: 100vh;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 2rem 1rem;
                text-align: center;
            }
            .brand { display: inline-flex; align-items: center; gap: .6rem; color: var(--primary); font-size: 1.25rem; font-weight: 600; text-decoration: none; }
            .brand svg { width: 2.25rem; height: 2.25rem; }
            .code { margin: 3rem 0 0; color: var(--primary); font-size: clamp(5rem, 22vw, 9rem); font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
            h1 { margin: 1rem 0 0; font-size: 1.5rem; font-weight: 600; }
            p.help { max-width: 26rem; margin: .75rem 0 0; color: rgba(11, 46, 41, .75); font-size: 1.05rem; line-height: 1.5; }
            /* The canonical primary button (see .btn-primary in resources/css/app.css): light fill, glossy teal on hover. */
            .btn-primary {
                display: inline-flex;
                min-height: 2.75rem;
                align-items: center;
                justify-content: center;
                margin-top: 2rem;
                padding: 12px 24px;
                border: none;
                border-radius: 999px;
                background: #E4F5E9;
                color: var(--primary);
                font-size: 1rem;
                font-weight: 700;
                text-decoration: none;
                transition: all .15s ease;
            }
            .btn-primary:hover { background: linear-gradient(135deg, #3EA890 0%, #1E6B5C 100%); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(30, 94, 99, .28), inset 0 1px 0 rgba(255, 255, 255, .35); }
            .btn-primary:focus-visible, .brand:focus-visible { outline: 2px solid var(--primary); outline-offset: 3px; }
            @media (prefers-reduced-motion: reduce) { .btn-primary { transition: none; } .btn-primary:hover { transform: none; } }
        </style>
    </head>
    <body>
        <main>
            <a class="brand" href="{{ url('/') }}">
                <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <defs>
                        <linearGradient id="careflow-gloss" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
                            <stop offset="0" stop-color="#3EA890"/>
                            <stop offset="1" stop-color="#1E6B5C"/>
                        </linearGradient>
                    </defs>
                    <rect width="32" height="32" rx="8" fill="url(#careflow-gloss)"/>
                    <path d="M9 1h14" stroke="#FFFFFF" stroke-opacity="0.35" stroke-linecap="round"/>
                    <path d="M16 9v14M9 16h14" stroke="#FFFFFF" stroke-width="3" stroke-linecap="round"/>
                </svg>
                CareFlow
            </a>

            <p class="code">{{ $code }}</p>
            <h1>{{ $title }}</h1>
            <p class="help">{{ $help }}</p>

            <a class="btn-primary" href="{{ url('/') }}">Back to Home</a>
        </main>
    </body>
</html>
