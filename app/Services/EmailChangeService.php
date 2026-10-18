<?php
namespace App\Services;

use App\Mail\CustomerVerificationCodeMail;
use App\Models\CustomerVerificationCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class EmailChangeService
{
    private const WINDOW = 15;
    public function type(Model $account): string { return match ($account::class) { \App\Models\Customer::class => 'customer', \App\Models\Admin::class => 'admin', \App\Models\Seller::class => 'seller', default => 'reseller' }; }
    private function codes(Model $a) { return CustomerVerificationCode::query()->where('account_type',$this->type($a))->where('account_id',$a->getKey()); }
    private function valid(Model $a,string $email,string $type,string $code): ?CustomerVerificationCode { return $this->codes($a)->where('email',$email)->where('type',$type)->where('code',$code)->whereNull('used_at')->where('expires_at','>',now())->latest()->first(); }
    private function confirmed(Model $a): bool { return !$a->email || $this->codes($a)->where('type','email_change_current')->where('email',$a->email)->where('used_at','>',now()->subMinutes(self::WINDOW))->exists(); }
    private function taken(Model $a,string $email): bool { return $a->newQuery()->where('email',$email)->whereKeyNot($a->getKey())->exists(); }
    private function send(Model $a,string $email,string $type): void { $this->codes($a)->where('type',$type)->whereNull('used_at')->delete(); $code=(string)random_int(100000,999999); CustomerVerificationCode::create(['customer_id'=>$a instanceof \App\Models\Customer ? $a->id : null,'account_type'=>$this->type($a),'account_id'=>$a->id,'email'=>$email,'code'=>$code,'type'=>$type,'expires_at'=>now()->addMinutes(10)]); Mail::to($email)->send(new CustomerVerificationCodeMail($code,$type)); }
    public function sendCurrent(Model $a): array { if (!$a->email) return ['message'=>'No current email to confirm.','step'=>'enter']; $this->codes($a)->whereIn('type',['email_change_current','email_change_new'])->delete(); $a->forceFill(['pending_email'=>null])->save(); $this->send($a,$a->email,'email_change_current'); return ['message'=>'Code sent.','step'=>'current']; }
    public function verifyCurrent(Model $a,string $code): array { if (!$a->email || !($row=$this->valid($a,$a->email,'email_change_current',$code))) throw ValidationException::withMessages(['code'=>['Invalid or expired code.']]); $row->update(['used_at'=>now()]); return ['message'=>'Email confirmed.','step'=>'enter']; }
    public function request(Model $a,string $email): array { $email=strtolower(trim($email)); if (!$this->confirmed($a)) throw ValidationException::withMessages(['email'=>['Confirm your current email first.']]); if (strcasecmp($email,(string)$a->email)===0) throw ValidationException::withMessages(['email'=>['That is already your email.']]); if ($this->taken($a,$email)) throw ValidationException::withMessages(['email'=>['Email already taken.']]); $this->codes($a)->where('type','email_change_new')->whereNull('used_at')->delete(); $a->forceFill(['pending_email'=>$email])->save(); $this->send($a,$email,'email_change_new'); return ['message'=>'Code sent.','step'=>'new','pending_email'=>$email]; }
    public function verifyNew(Model $a,string $code): Model { $email=$a->pending_email; if (!$email || !$this->confirmed($a)) throw ValidationException::withMessages(['code'=>['Start the email change again.']]); if (!($row=$this->valid($a,$email,'email_change_new',$code))) throw ValidationException::withMessages(['code'=>['Invalid or expired code.']]); if ($this->taken($a,$email)) throw ValidationException::withMessages(['email'=>['Email already taken.']]); $a->forceFill(['email'=>$email,'pending_email'=>null]); if (in_array('email_verified_at',$a->getFillable(),true) || array_key_exists('email_verified_at',$a->getAttributes())) $a->email_verified_at=now(); $a->save(); $this->codes($a)->whereIn('type',['email_change_current','email_change_new'])->delete(); return $a->fresh(); }
    public function resend(Model $a,string $step): array { if ($step==='current') { if (!$a->email) throw ValidationException::withMessages(['step'=>['No current email to confirm.']]); $this->send($a,$a->email,'email_change_current'); } else { if (!$a->pending_email || !$this->confirmed($a)) throw ValidationException::withMessages(['step'=>['Start the email change again.']]); $this->send($a,$a->pending_email,'email_change_new'); } return ['message'=>'Code sent.','step'=>$step]; }
}
