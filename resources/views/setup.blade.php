<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Initial store setup</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f5f5f5; margin: 0; padding: 2rem; color: #1f2937; }
        main { max-width: 720px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: .75rem; box-shadow: 0 1px 4px #0002; }
        fieldset { border: 1px solid #d1d5db; border-radius: .5rem; margin: 1.5rem 0; padding: 1rem; }
        label { display: block; font-weight: 600; margin-top: .75rem; }
        input, textarea { box-sizing: border-box; width: 100%; margin-top: .25rem; padding: .6rem; border: 1px solid #9ca3af; border-radius: .35rem; }
        button { padding: .7rem 1rem; border: 0; border-radius: .35rem; background: #111827; color: #fff; font-weight: 700; cursor: pointer; }
        .errors { color: #b91c1c; }
    </style>
</head>
<body>
<main>
    <h1>Initial store setup</h1>
    <p>This one-time form creates the store profile and its first super-admin account.</p>

    @if ($errors->any())
        <ul class="errors">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('setup.store') }}" enctype="multipart/form-data">
        @csrf
        <fieldset>
            <legend>Store</legend>
            <label>Store name<input name="store_name" value="{{ old('store_name') }}" required></label>
            <label>Store logo (optional)<input type="file" name="store_logo" accept="image/*"></label>
        </fieldset>
        <fieldset>
            <legend>First super admin</legend>
            <label>Name<input name="admin_name" value="{{ old('admin_name') }}" required></label>
            <label>Email<input type="email" name="admin_email" value="{{ old('admin_email') }}" required></label>
            <label>Password<input type="password" name="admin_password" required></label>
            <label>Confirm password<input type="password" name="admin_password_confirmation" required></label>
            <label>Phone (optional)<input name="admin_phone" value="{{ old('admin_phone') }}"></label>
            <label>Address (optional)<textarea name="admin_address">{{ old('admin_address') }}</textarea></label>
            <label>Profile image (optional)<input type="file" name="admin_image" accept="image/*"></label>
        </fieldset>
        <button type="submit">Complete setup</button>
    </form>
</main>
</body>
</html>
