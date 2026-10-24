@extends('layouts.page')

@section('title', 'Set up your store')
@section('heading', 'Set up your store')
@section('description', "This runs once. It creates your store and the account you'll sign in with.")

@section('icon')
    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 21v-5a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v5"/><path d="M17.774 10.31a1.12 1.12 0 0 0-1.549 0 2.5 2.5 0 0 1-3.451 0 1.12 1.12 0 0 0-1.548 0 2.5 2.5 0 0 1-3.452 0 1.12 1.12 0 0 0-1.549 0 2.5 2.5 0 0 1-3.77-3.248l2.889-4.184A2 2 0 0 1 7 2h10a2 2 0 0 1 1.653.873l2.895 4.192a2.5 2.5 0 0 1-3.774 3.244"/><path d="M4 10.95V19a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8.05"/></svg>
@endsection

@php
    // Same districts and Steadfast thanas as the Next app (src/lib/data.ts, src/lib/steadfast-areas.ts).
    $areas = json_decode(file_get_contents(resource_path('brand/bd-areas.json')), true);
    // A mismatch is reported on the password field; show it under Confirm like /register does.
    $mismatch = "Passwords don't match.";
    $passwordError = $errors->first('admin_password');
    $confirmError = $passwordError === $mismatch ? $mismatch : null;
    if ($confirmError) $passwordError = null;
    $invalid = fn (string $name) => $errors->has($name) ? 'aria-invalid=true aria-describedby='.$name.'-error' : '';
    $oldArea = old('address_area');
    $oldDistrict = old('address_district');
@endphp

@section('content')
    <form method="POST" action="{{ route('setup.store') }}" enctype="multipart/form-data" novalidate>
        @csrf

        <div class="field">
            <label class="label" for="admin_name">Full name</label>
            <input class="input" id="admin_name" name="admin_name" value="{{ old('admin_name') }}" placeholder="Your full name" autocomplete="name" required {!! $invalid('admin_name') !!}>
            @error('admin_name')<p class="error" id="admin_name-error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label class="label" for="admin_email">Your email address</label>
            <input class="input" id="admin_email" type="email" name="admin_email" value="{{ old('admin_email') }}" placeholder="you@example.com" autocomplete="email" required {!! $invalid('admin_email') !!}>
            @error('admin_email')<p class="error" id="admin_email-error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label class="label" for="admin_phone">Your mobile number</label>
            <input class="input" id="admin_phone" type="tel" name="admin_phone" value="{{ old('admin_phone') }}" placeholder="01700000000" autocomplete="tel" inputmode="numeric" maxlength="11" required {!! $invalid('admin_phone') !!}>
            @error('admin_phone')<p class="error" id="admin_phone-error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label class="label" for="admin_password">Password</label>
            <div class="password">
                <input class="input" id="admin_password" type="password" name="admin_password" placeholder="At least 8 characters" autocomplete="new-password" minlength="8" required
                    @if ($passwordError) aria-invalid="true" aria-describedby="admin_password-error" @endif>
                <button type="button" class="reveal" aria-label="Show password" data-reveal>
                    <svg class="icon icon-sm" viewBox="0 0 24 24" aria-hidden="true" data-eye><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="icon icon-sm" viewBox="0 0 24 24" aria-hidden="true" data-eye-off hidden><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg>
                </button>
            </div>
            @if ($passwordError)<p class="error" id="admin_password-error">{{ $passwordError }}</p>@endif
        </div>

        <div class="field">
            <label class="label" for="admin_password_confirmation">Confirm password</label>
            <input class="input" id="admin_password_confirmation" type="password" name="admin_password_confirmation" placeholder="Re-enter your password" autocomplete="new-password" required
                @if ($confirmError) aria-invalid="true" aria-describedby="admin_password_confirmation-error" @endif>
            @if ($confirmError)<p class="error" id="admin_password_confirmation-error">{{ $confirmError }}</p>@endif
        </div>

        <div class="row-sku">
            <div class="field">
                <label class="label" for="store_name">Store name</label>
                <input class="input" id="store_name" name="store_name" value="{{ old('store_name') }}" placeholder="e.g. Dhaka Threads" maxlength="255" required {!! $invalid('store_name') !!}>
                @error('store_name')<p class="error" id="store_name-error">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label class="label" for="sku_prefix">SKU code</label>
                <input class="input mono" id="sku_prefix" name="sku_prefix" value="{{ old('sku_prefix') }}" placeholder="ARSH" maxlength="4" autocomplete="off" required {!! $invalid('sku_prefix') !!}>
                @error('sku_prefix')<p class="error" id="sku_prefix-error">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="field">
            <label class="label" for="support_email">Store email</label>
            <input class="input" id="support_email" type="email" name="support_email" value="{{ old('support_email') }}" placeholder="support@yourstore.com" required {!! $invalid('support_email') !!}>
            @error('support_email')<p class="error" id="support_email-error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label class="label" for="support_phone">Store mobile number</label>
            <input class="input" id="support_phone" type="tel" name="support_phone" value="{{ old('support_phone') }}" placeholder="01700000000" inputmode="numeric" maxlength="11" required {!! $invalid('support_phone') !!}>
            @error('support_phone')<p class="error" id="support_phone-error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label class="label" for="address_line">Store address</label>
            <input class="input" id="address_line" name="address_line" value="{{ old('address_line') }}" placeholder="House 42, Road 11, Banani" required {!! $invalid('address_line') !!}>
            @error('address_line')<p class="error" id="address_line-error">{{ $message }}</p>@enderror
        </div>

        {{-- Each thana belongs to one district, so picking it fills District. --}}
        <div class="row-2">
            <div class="field">
                <label class="label" for="area_search">Area / Thana</label>
                <input type="hidden" name="address_area" id="address_area" value="{{ $oldArea }}">
                <input class="input" id="area_search" list="thana_options" value="{{ $oldArea }}" placeholder="Select thana" autocomplete="off" required
                    @if ($errors->has('address_area')) aria-invalid="true" aria-describedby="area_search-error" @endif>
                <datalist id="thana_options"></datalist>
                @error('address_area')<p class="error" id="area_search-error">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label class="label" for="address_district">District</label>
                <select class="input select" id="address_district" name="address_district" required {!! $invalid('address_district') !!}>
                    <option value="" disabled @selected(!$oldDistrict)>Select district</option>
                    @foreach ($areas['districts'] as $district)
                        <option value="{{ $district }}" @selected($oldDistrict === $district)>{{ $district }}</option>
                    @endforeach
                </select>
                @error('address_district')<p class="error" id="address_district-error">{{ $message }}</p>@enderror
            </div>
        </div>

        @include('partials.upload-field', ['name' => 'store_logo', 'label' => 'Store logo', 'hint' => 'Square PNG or JPG, up to 5 MB'])

        <button type="submit" class="btn btn-primary btn-block">
            <svg class="icon icon-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 21v-5a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v5"/><path d="M17.774 10.31a1.12 1.12 0 0 0-1.549 0 2.5 2.5 0 0 1-3.451 0 1.12 1.12 0 0 0-1.548 0 2.5 2.5 0 0 1-3.452 0 1.12 1.12 0 0 0-1.549 0 2.5 2.5 0 0 1-3.77-3.248l2.889-4.184A2 2 0 0 1 7 2h10a2 2 0 0 1 1.653.873l2.895 4.192a2.5 2.5 0 0 1-3.774 3.244"/><path d="M4 10.95V19a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8.05"/></svg>
            Complete setup
        </button>
    </form>

    <script>
        const THANAS = @json($areas['thanas']);

        // Show/hide both password fields together, like /register.
        const reveal = document.querySelector('[data-reveal]');
        reveal.addEventListener('click', () => {
            const show = reveal.getAttribute('aria-label') === 'Show password';
            document.querySelectorAll('#admin_password, #admin_password_confirmation').forEach((el) => el.type = show ? 'text' : 'password');
            reveal.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            reveal.querySelector('[data-eye]').hidden = show;
            reveal.querySelector('[data-eye-off]').hidden = !show;
        });

        // SKU code: letters and digits only, upper-cased (same rule as the console).
        const sku = document.getElementById('sku_prefix');
        sku.addEventListener('input', () => { sku.value = sku.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 4); });

        // Area / Thana: search every thana as "Thana, District"; the chosen district's thanas come first.
        const areaSearch = document.getElementById('area_search');
        const area = document.getElementById('address_area');
        const district = document.getElementById('address_district');
        const list = document.getElementById('thana_options');
        const fillThanas = () => {
            const mine = district.value;
            const rows = Object.entries(THANAS).sort(([a], [b]) => (a === mine ? -1 : b === mine ? 1 : 0));
            list.replaceChildren(...rows.flatMap(([d, ts]) => ts.map((t) => new Option(`${t}, ${d}`))));
        };
        const clearError = (el) => {
            el.removeAttribute('aria-invalid');
            document.getElementById(el.id + '-error')?.remove();
        };
        areaSearch.addEventListener('input', () => {
            const [t, d] = areaSearch.value.split(', ');
            if (d && THANAS[d]?.includes(t)) {
                area.value = t;
                areaSearch.value = t;
                district.value = d;
                clearError(district);
                fillThanas();
            } else {
                area.value = '';
            }
        });
        district.addEventListener('change', () => {
            // A thana from another district no longer applies.
            if (!THANAS[district.value]?.includes(area.value)) { area.value = ''; areaSearch.value = ''; }
            fillThanas();
        });
        fillThanas();

        // Logo picker: drop or Upload fills the hidden file input, then a preview tile replaces the drop area.
        document.querySelectorAll('[data-upload]').forEach((field) => {
            const input = field.querySelector('input[type=file]');
            const drop = field.querySelector('[data-drop]');
            const tile = field.querySelector('[data-tile]');
            const img = field.querySelector('[data-preview]');
            let url = null;
            const show = () => {
                const file = input.files[0];
                if (url) URL.revokeObjectURL(url);
                url = file ? URL.createObjectURL(file) : null;
                img.src = url ?? '';
                img.alt = file?.name ?? '';
                drop.hidden = !!file;
                tile.hidden = !file;
                drop.classList.remove('invalid');
                field.querySelector('.error')?.remove();
            };
            const set = (file) => {
                const dt = new DataTransfer();
                if (file) dt.items.add(file);
                input.files = dt.files;
                show();
            };
            field.querySelector('[data-pick]').addEventListener('click', () => input.click());
            field.querySelector('[data-remove]').addEventListener('click', () => set(null));
            input.addEventListener('change', show);
            drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('over'); });
            drop.addEventListener('dragleave', () => drop.classList.remove('over'));
            drop.addEventListener('drop', (e) => {
                e.preventDefault();
                drop.classList.remove('over');
                const file = e.dataTransfer.files?.[0];
                if (file && file.type.startsWith('image/')) set(file);
            });
        });

        // Clear a field's error once it's edited.
        document.querySelector('form').addEventListener('input', (e) => {
            if (e.target.getAttribute('aria-invalid') === 'true') clearError(e.target);
        });
        document.querySelector('form').addEventListener('change', (e) => {
            if (e.target.getAttribute('aria-invalid') === 'true') clearError(e.target);
        });

        document.querySelector('[aria-invalid=true], .drop.invalid [data-pick]')?.focus();
    </script>
@endsection
