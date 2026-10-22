<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title', 'NEXF API')</title>
    <style>
        :root { --blue:#056fff; --ink:#172033; --muted:#667085; --line:#d9e0ea; --surface:#fff; --page:#f4f7fb; --soft:#eef5ff; --danger:#c23b3b; }
        @media (prefers-color-scheme: dark) { :root { --ink:#f4f7fb; --muted:#aab6c9; --line:#2b3850; --surface:#101827; --page:#09111f; --soft:#10284c; --danger:#ff9c9c; } }
        * { box-sizing:border-box } body { margin:0; min-height:100vh; background:var(--page); color:var(--ink); font:15px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .bar { height:68px; background:var(--surface); border-bottom:1px solid var(--line); display:flex; align-items:center; padding:0 max(24px, calc((100vw - 1180px)/2)); } .brand img{width:112px;height:auto;display:block}
        .shell{width:min(100% - 32px, 780px);margin:42px auto}.card{background:var(--surface);border:1px solid var(--line);border-radius:18px;box-shadow:0 12px 40px #17203312;padding:32px}.eyebrow{color:var(--blue);font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;margin:0 0 6px}.title{font-size:27px;line-height:1.2;margin:0}.lead{color:var(--muted);margin:8px 0 0}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.full{grid-column:1/-1}fieldset{padding:0;border:0;margin:30px 0}legend{font-size:17px;font-weight:750;margin-bottom:15px}label{font-weight:650;display:block}input,select,textarea{width:100%;border:1px solid var(--line);border-radius:9px;background:transparent;color:var(--ink);padding:11px 12px;margin-top:6px;outline:0}input:focus,select:focus,textarea:focus{border-color:var(--blue);box-shadow:0 0 0 3px #056fff20}textarea{min-height:90px;resize:vertical}.hint{color:var(--muted);font-size:12px;margin:5px 0 0}.error{color:var(--danger);font-size:13px;margin:5px 0 0}.errors{border:1px solid #c23b3b55;background:#c23b3b12;border-radius:10px;color:var(--danger);padding:12px 16px;margin:22px 0}.errors ul{margin:0;padding-left:18px}.button{margin-top:4px;background:var(--blue);border:0;border-radius:9px;padding:12px 18px;color:#fff;font-weight:750;cursor:pointer;font-size:15px}.button:hover{filter:brightness(.94)}.success{border:1px solid #159c5550;background:#159c5512;border-radius:10px;padding:13px 16px;color:#159c55;margin:20px 0}.actions{display:flex;justify-content:flex-end;margin-top:24px}.upload{border:1px dashed var(--line);border-radius:11px;padding:18px;text-align:center;background:var(--soft)}.upload img{max-width:160px;max-height:90px;margin:0 auto 10px;object-fit:contain}.upload input{display:none}.upload label{color:var(--blue);cursor:pointer}.remove{background:none;border:0;color:var(--danger);cursor:pointer;margin-top:7px}@media(max-width:620px){.shell{width:min(100% - 24px,780px);margin:24px auto}.card{padding:22px}.grid{grid-template-columns:1fr}.full{grid-column:auto}.bar{padding:0 16px}}
    </style>
    @stack('head')
</head>
<body>
<header class="bar"><a class="brand" href="{{ route('home') }}"><img alt="NEXF" src="data:image/svg+xml;base64,{{ base64_encode(file_get_contents(resource_path('brand/logo.svg'))) }}"></a></header>
<main class="shell">@yield('content')</main>
@stack('scripts')
</body>
</html>
