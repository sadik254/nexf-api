@extends('layouts.page')
@section('title', 'Set up your store · NEXF')
@section('content')
<div class="card">
    <p class="eyebrow">Welcome to NEXF</p>
    <h1 class="title">Set up your store</h1>
    <p class="lead">Create your store and the first console account. This page is available only once.</p>
    @if ($errors->any())<div class="errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('setup.store') }}" enctype="multipart/form-data">
        @csrf
        <fieldset><legend>Your account</legend><div class="grid">
            <label>Full name<input name="admin_name" value="{{ old('admin_name') }}" required autocomplete="name">@error('admin_name')<p class="error">{{ $message }}</p>@enderror</label>
            <label>Your email address<input type="email" name="admin_email" value="{{ old('admin_email') }}" required autocomplete="email">@error('admin_email')<p class="error">{{ $message }}</p>@enderror</label>
            <label>Your mobile number<input name="admin_phone" value="{{ old('admin_phone') }}" required inputmode="numeric" placeholder="01XXXXXXXXX" autocomplete="tel">@error('admin_phone')<p class="error">{{ $message }}</p>@enderror</label>
            <span></span>
            <label>Password<input type="password" name="admin_password" required autocomplete="new-password">@error('admin_password')<p class="error">{{ $message }}</p>@enderror</label>
            <label>Confirm password<input type="password" name="admin_password_confirmation" required autocomplete="new-password"></label>
        </div></fieldset>
        <fieldset><legend>Your store</legend><div class="grid">
            <label>Store name<input name="store_name" value="{{ old('store_name') }}" required>@error('store_name')<p class="error">{{ $message }}</p>@enderror</label>
            <label>SKU code<input name="sku_prefix" value="{{ old('sku_prefix') }}" required maxlength="4" style="text-transform:uppercase"><p class="hint">Exactly 4 letters or digits.</p>@error('sku_prefix')<p class="error">{{ $message }}</p>@enderror</label>
            <label>Store email<input type="email" name="store_email" value="{{ old('store_email') }}" required>@error('store_email')<p class="error">{{ $message }}</p>@enderror</label>
            <label>Store mobile number<input name="store_phone" value="{{ old('store_phone') }}" required inputmode="numeric" placeholder="01XXXXXXXXX">@error('store_phone')<p class="error">{{ $message }}</p>@enderror</label>
            <label class="full">Store address<textarea name="address_line" required>{{ old('address_line') }}</textarea>@error('address_line')<p class="error">{{ $message }}</p>@enderror</label>
            <label>District<select name="address_district" required><option value="">Select district</option>@foreach($areas['districts'] as $district)<option value="{{ $district }}" @selected(old('address_district') === $district)>{{ $district }}</option>@endforeach</select>@error('address_district')<p class="error">{{ $message }}</p>@enderror</label>
            <label>Area / Thana<select name="address_area" required><option value="">Select district first</option></select>@error('address_area')<p class="error">{{ $message }}</p>@enderror</label>
            <div class="full"><label>Store logo <span class="hint">(optional)</span></label>@include('partials.upload-field')@error('store_logo')<p class="error">{{ $message }}</p>@enderror</div>
        </div></fieldset>
        <div class="actions"><button class="button" type="submit">Set up store</button></div>
    </form>
</div>
@endsection
@push('scripts')
<script>
const areas=@json($areas),district=document.querySelector('[name="address_district"]'),area=document.querySelector('[name="address_area"]'),oldArea=@json(old('address_area'));
function setAreas(){const current=area.value||oldArea;area.innerHTML='<option value="">Select area / thana</option>';(areas.thanas[district.value]||[]).forEach(name=>{const option=new Option(name,name,false,name===current);area.add(option)});} district.addEventListener('change',setAreas);setAreas();
const box=document.querySelector('[data-upload-field]'),input=box.querySelector('input'),image=box.querySelector('img'),remove=box.querySelector('.remove');input.addEventListener('change',()=>{const file=input.files[0];if(!file)return;image.src=URL.createObjectURL(file);image.hidden=false;remove.hidden=false;});remove.addEventListener('click',()=>{input.value='';image.removeAttribute('src');image.hidden=true;remove.hidden=true;});
</script>
@endpush
