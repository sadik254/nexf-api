<?php
namespace App\Http\Controllers;
use App\Models\Customer;
use App\Models\MediaAsset;
use App\Services\MediaUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
class CustomerReviewMediaController extends Controller {
 public function __construct(private MediaUploadService $uploads){}
 public function store(Request $request):JsonResponse {
  $customer=$request->user();abort_unless($customer instanceof Customer,403);
  $data=$request->validate(['file'=>['required','file','mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm','max:20480']]);
  $file=$data['file'];$asset=MediaAsset::create(['owner_type'=>'customer','owner_id'=>$customer->id,'source'=>'upload','url'=>$this->uploads->upload($file),'file_name'=>$file->getClientOriginalName(),'mime_type'=>$file->getMimeType(),'size_bytes'=>$file->getSize()]);
  return response()->json($asset,201);
 }
}
