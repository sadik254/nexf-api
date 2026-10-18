<?php
namespace App\Http\Controllers\Concerns;
use App\Services\EmailChangeService;
use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
trait HandlesEmailChange {
  abstract protected function emailChangeKey(): string;
  public function sendCurrentEmailCode(Request $r, EmailChangeService $s): JsonResponse { return response()->json($s->sendCurrent($r->user())); }
  public function verifyCurrentEmail(Request $r, EmailChangeService $s): JsonResponse { $d=$r->validate(['code'=>['required','digits:6']]); return response()->json($s->verifyCurrent($r->user(),$d['code'])); }
  public function requestEmailChange(Request $r, EmailChangeService $s): JsonResponse { $d=$r->validate(['email'=>['required','email','max:255']]); return response()->json($s->request($r->user(),$d['email'])); }
  public function verifyNewEmail(Request $r, EmailChangeService $s): JsonResponse { $d=$r->validate(['code'=>['required','digits:6']]); $a=$s->verifyNew($r->user(),$d['code']); return response()->json(['message'=>'Email updated.',$this->emailChangeKey()=>$a]); }
  public function resendEmailChangeCode(Request $r, EmailChangeService $s): JsonResponse { $d=$r->validate(['step'=>['required','in:current,new']]); return response()->json($s->resend($r->user(),$d['step'])); }
}
