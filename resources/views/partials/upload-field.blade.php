{{-- One-image upload like the seller-register UploadField. Needs the script in setup.blade.php. --}}
<div class="field" data-upload>
    <span class="label" id="{{ $name }}-label">{{ $label }} <span class="optional">(optional)</span></span>
    <input type="file" id="{{ $name }}" name="{{ $name }}" accept="image/*" hidden aria-labelledby="{{ $name }}-label">
    <div class="drop {{ $errors->has($name) ? 'invalid' : '' }}" data-drop>
        <button type="button" class="btn btn-outline btn-xs" data-pick>
            <svg class="icon icon-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"/><path d="m17 8-5-5-5 5"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/></svg>
            Upload
        </button>
        <p class="hint">{{ $hint }}</p>
    </div>
    <div class="tile" data-tile hidden>
        <img alt="" data-preview>
        <button type="button" class="tile-remove" data-remove aria-label="Remove image">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
    </div>
    @error($name)<p class="error" id="{{ $name }}-error">{{ $message }}</p>@enderror
</div>
