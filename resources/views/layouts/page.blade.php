{{-- Mirrors the Next app's (auth) layout and AuthCard, so setup and the API home look like /register. --}}
@php
    $logo = preg_replace('/<\?xml.*?\?>|<!--.*?-->/s', '', file_get_contents(resource_path('brand/logo.svg')));
    $siteUrl = rtrim((string) config('app.frontend_url'), '/');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | NEXF Lifestyle</title>
    <script>
        // Before paint, so there's no flash: the saved choice, else the system's.
        (() => {
            let saved = null;
            try { saved = localStorage.getItem('theme'); } catch {}
            const system = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.dataset.theme = saved === 'dark' || saved === 'light' ? saved : system;
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Same tokens as src/app/globals.css. */
        :root {
            color-scheme: light;
            --background: oklch(1 0 0);
            --foreground: oklch(0.129 0.042 264.695);
            --card: oklch(1 0 0);
            --muted: oklch(0.968 0.007 247.896);
            --muted-foreground: oklch(0.554 0.046 257.417);
            --primary: oklch(0.581 0.230 259.7);
            --primary-foreground: oklch(0.97 0.014 254.604);
            --primary-text: oklch(0.5 0.2 259.7);
            --primary-text-hover: oklch(0.42 0.2 259.7);
            --destructive: oklch(0.577 0.245 27.325);
            --border: oklch(0.929 0.013 255.508);
            --input: oklch(0.929 0.013 255.508);
            --ring: oklch(0.704 0.04 256.788);
            --radius: 0.45rem;
            --glow-1: oklch(0.581 0.230 259.7 / 5%);
            --glow-2: oklch(0.746 0.16 232.661 / 10%);
            --glow-3: oklch(0.702 0.183 293.541 / 10%);
        }
        /* data-theme is set before paint (script below): the saved choice, else the system's. */
        :root[data-theme=dark] {
            color-scheme: dark;
            --background: oklch(0.255 0.012 264);
            --foreground: oklch(0.985 0 0);
            --card: oklch(0.3 0.018 264);
            --muted: oklch(0.274 0.018 264);
            --muted-foreground: oklch(0.705 0.03 256);
            --primary-text: oklch(0.72 0.17 259.7);
            --primary-text-hover: oklch(0.64 0.17 259.7);
            --destructive: oklch(0.704 0.191 22.216);
            --border: oklch(1 0 0 / 10%);
            --input: oklch(1 0 0 / 15%);
            --ring: oklch(0.552 0.027 264);
            --glow-2: oklch(0.685 0.169 237.323 / 5%);
            --glow-3: oklch(0.606 0.25 292.717 / 5%);
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0; background: var(--background); color: var(--foreground);
            font-family: Inter, ui-sans-serif, system-ui, sans-serif; -webkit-font-smoothing: antialiased;
        }
        svg { display: block; }
        [hidden] { display: none !important; }
        .shell { position: relative; display: flex; flex-direction: column; min-height: 100dvh; overflow: hidden; }
        .glow { position: absolute; border-radius: 9999px; filter: blur(64px); pointer-events: none; }
        .glow-1 { top: -8rem; left: -6rem; width: 30rem; height: 30rem; background: var(--glow-1); }
        .glow-2 { top: 25%; right: -7rem; width: 26rem; height: 26rem; background: var(--glow-2); }
        .glow-3 { bottom: -9rem; left: 33%; width: 28rem; height: 28rem; background: var(--glow-3); }
        header, main, footer { position: relative; z-index: 1; }
        .topbar { display: flex; align-items: center; justify-content: space-between; height: 64px; max-width: 1400px; margin: 0 auto; width: 100%; padding: 0 1rem; }
        @media (min-width: 640px) { .topbar { padding: 0 1.5rem; } }
        @media (min-width: 1024px) { .topbar { padding: 0 2rem; } }
        .topbar .logo svg { width: 92px; height: auto; }
        main { flex: 1; display: flex; align-items: center; justify-content: center; padding: 40px 20px; }
        .wrap { width: 100%; max-width: 440px; animation: rise .4s cubic-bezier(.22,1,.36,1) both; }
        @keyframes rise { from { opacity: 0; transform: translateY(16px); } }
        @media (prefers-reduced-motion: reduce) { .wrap { animation: none; } }
        .card {
            background: color-mix(in oklab, var(--card) 25%, transparent); border: 1px solid var(--border);
            border-radius: calc(var(--radius) + 4px); padding: 24px; box-shadow: 0 1px 3px 0 #0000001a, 0 1px 2px -1px #0000001a;
        }
        @media (min-width: 640px) { .card { padding: 32px; } }
        .icon-tile {
            display: grid; place-items: center; width: 44px; height: 44px; margin-bottom: 20px;
            border-radius: var(--radius); background: color-mix(in oklab, var(--primary) 10%, transparent); color: var(--primary-text);
        }
        .icon { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .icon-sm { width: 16px; height: 16px; }
        h1 { margin: 0; font-size: 24px; line-height: 32px; font-weight: 700; letter-spacing: -0.025em; }
        .lead { margin: 6px 0 0; color: var(--muted-foreground); font-size: 14px; line-height: 1.625; }
        .body { margin-top: 24px; }
        .below { margin-top: 20px; text-align: center; color: var(--muted-foreground); font-size: 14px; }
        a.link, .below a { color: var(--primary-text); font-weight: 500; text-decoration: none; transition: color .15s; }
        a.link:hover, .below a:hover { color: var(--primary-text-hover); }
        footer { padding: 24px 20px; text-align: center; color: var(--muted-foreground); font-size: 14px; }

        form { display: flex; flex-direction: column; gap: 16px; }
        .field { display: flex; flex-direction: column; gap: 8px; }
        .label { font-size: 14px; line-height: 1; font-weight: 500; }
        .optional { color: var(--muted-foreground); font-weight: 400; }
        .input {
            width: 100%; min-width: 0; height: 36px; padding: 4px 12px; font: inherit; font-size: 14px; color: inherit;
            background: transparent; border: 1px solid var(--input); border-radius: calc(var(--radius) - 2px);
            box-shadow: 0 1px 2px 0 #0000000d; outline: none; transition: color .15s, box-shadow .15s;
        }
        textarea.input { height: auto; min-height: 72px; padding: 8px 12px; resize: vertical; }
        .input::placeholder { color: color-mix(in oklab, var(--muted-foreground) 50%, transparent); }
        .input:focus-visible { border-color: var(--ring); box-shadow: 0 0 0 3px color-mix(in oklab, var(--ring) 50%, transparent); }
        .input[aria-invalid=true] { border-color: var(--destructive); }
        .input[aria-invalid=true]:focus-visible { box-shadow: 0 0 0 3px color-mix(in oklab, var(--destructive) 20%, transparent); }
        /* Same as the seller-register UploadField: dashed drop area, then a square preview tile. */
        .drop {
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; min-height: 128px;
            padding: 24px 16px; text-align: center; border: 1px dashed color-mix(in oklab, var(--muted-foreground) 35%, transparent);
            border-radius: calc(var(--radius) + 4px); transition: border-color .15s, background .15s;
        }
        .drop.over { border-color: var(--primary); background: color-mix(in oklab, var(--primary) 5%, transparent); }
        .drop.invalid { border-color: var(--destructive); }
        .hint { margin: 0; color: var(--muted-foreground); font-size: 12px; line-height: 16px; }
        .tile { position: relative; width: 96px; height: 96px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--muted); }
        .tile img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; border-radius: inherit; }
        .tile-remove {
            position: absolute; top: -6px; right: -6px; z-index: 1; display: grid; place-items: center; width: 20px; height: 20px;
            padding: 0; border: 0; border-radius: 9999px; cursor: pointer; transition: background .15s;
            background: color-mix(in oklab, var(--foreground) 80%, transparent); color: var(--background);
        }
        .tile-remove:hover { background: var(--foreground); }
        .tile-remove svg { width: 12px; height: 12px; }
        .row-sku, .row-2 { display: grid; gap: 16px; }
        @media (min-width: 640px) {
            .row-sku { grid-template-columns: minmax(0, 1fr) 8rem; }
            .row-2 { grid-template-columns: 1fr 1fr; }
        }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; text-transform: uppercase; }
        .mono::placeholder { text-transform: none; }
        select.input {
            appearance: none; padding-right: 32px; cursor: pointer; background-repeat: no-repeat; background-position: right 10px center; background-size: 16px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
        }
        select.input:invalid { color: color-mix(in oklab, var(--muted-foreground) 50%, transparent); }
        select.input option { color: var(--foreground); background: var(--card); }
        .password { position: relative; }
        .password .input { padding-right: 40px; }
        .reveal {
            position: absolute; top: 50%; right: 12px; transform: translateY(-50%); padding: 0; border: 0; background: none;
            color: var(--muted-foreground); cursor: pointer; transition: color .15s;
        }
        .reveal:hover { color: var(--foreground); }
        .error { margin: 0; color: var(--destructive); font-size: 12px; line-height: 16px; }
        .group-title { margin: 8px 0 0; padding-top: 16px; border-top: 1px solid var(--border); font-size: 13px; font-weight: 600; color: var(--muted-foreground); }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 40px; padding: 0 16px;
            border: 1px solid transparent; border-radius: calc(var(--radius) - 2px); font: inherit; font-size: 14px; font-weight: 500;
            white-space: nowrap; text-decoration: none; cursor: pointer; outline: none; transition: background .15s, color .15s;
        }
        .btn:focus-visible { box-shadow: 0 0 0 3px color-mix(in oklab, var(--ring) 50%, transparent); border-color: var(--ring); }
        .btn-primary { background: var(--primary); color: var(--primary-foreground); box-shadow: 0 1px 2px 0 #0000000d; }
        .btn-primary:hover { background: color-mix(in oklab, var(--primary) 90%, transparent); }
        .btn-outline { background: var(--card); color: var(--foreground); border-color: var(--border); box-shadow: 0 1px 2px 0 #0000000d; }
        .btn-outline:hover { background: var(--muted); }
        .btn-sm { height: 36px; }
        .btn-xs { height: 32px; gap: 6px; padding: 0 10px; }
        .btn-block { width: 100%; margin-top: 4px; }
        .stack { display: flex; flex-direction: column; gap: 10px; }
        .notice {
            margin: 0 0 16px; padding: 8px 12px; border-radius: calc(var(--radius) - 2px); font-size: 12px; line-height: 1.5;
            border: 1px solid oklch(0.696 0.17 162.48 / 30%); background: oklch(0.979 0.021 166.113); color: oklch(0.508 0.118 165.612);
        }
        :root[data-theme=dark] .notice { background: oklch(0.378 0.077 168.94 / 15%); color: oklch(0.765 0.177 163.223); }

        /* Same as the Next app's ThemeToggle: ghost icon button, sun in dark mode, moon in light. */
        .actions { display: flex; align-items: center; gap: 8px; }
        .theme-toggle {
            display: grid; place-items: center; width: 36px; height: 36px; padding: 0; border: 0; border-radius: calc(var(--radius) - 2px);
            background: none; color: var(--foreground); cursor: pointer; outline: none; transition: background .15s;
        }
        .theme-toggle:hover { background: color-mix(in oklab, var(--foreground) 5%, transparent); }
        .theme-toggle:focus-visible { box-shadow: 0 0 0 3px color-mix(in oklab, var(--ring) 50%, transparent); }
        .theme-toggle svg { width: 18px; height: 18px; }
        :root[data-theme=dark] [data-moon], :root:not([data-theme=dark]) [data-sun] { display: none; }
    </style>
</head>
<body>
<div class="shell">
    <div class="glow glow-1" aria-hidden="true"></div>
    <div class="glow glow-2" aria-hidden="true"></div>
    <div class="glow glow-3" aria-hidden="true"></div>

    <header>
        <div class="topbar">
            @if ($siteUrl)
                <a class="logo" href="{{ $siteUrl }}" aria-label="NEXF Lifestyle home">{!! $logo !!}</a>
            @else
                <span class="logo" role="img" aria-label="NEXF Lifestyle">{!! $logo !!}</span>
            @endif
            <div class="actions">
                <button type="button" class="theme-toggle" data-theme-toggle>
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" data-sun><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" data-moon><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
                </button>
                @if ($siteUrl)
                    <a class="btn btn-outline btn-sm" href="{{ $siteUrl }}">
                        <svg class="icon icon-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                        Back to site
                    </a>
                @endif
            </div>
        </div>
    </header>
    <script>
        (() => {
            const root = document.documentElement;
            const toggle = document.querySelector('[data-theme-toggle]');
            const label = () => toggle.setAttribute('aria-label', root.dataset.theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
            toggle.addEventListener('click', () => {
                root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
                try { localStorage.setItem('theme', root.dataset.theme); } catch {}
                label();
            });
            // Until a choice is saved, follow the system setting.
            matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                let saved = null;
                try { saved = localStorage.getItem('theme'); } catch {}
                if (!saved) { root.dataset.theme = e.matches ? 'dark' : 'light'; label(); }
            });
            label();
        })();
    </script>

    <main>
        <div class="wrap">
            <div class="card">
                <div class="icon-tile">@yield('icon')</div>
                <h1>@yield('heading')</h1>
                <p class="lead">@yield('description')</p>
                <div class="body">@yield('content')</div>
            </div>
            @hasSection('below')
                <div class="below">@yield('below')</div>
            @endif
        </div>
    </main>

    <footer>© {{ date('Y') }} NEXF Lifestyle</footer>
</div>
</body>
</html>
