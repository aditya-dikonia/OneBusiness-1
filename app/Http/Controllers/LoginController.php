<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Request;
use DB;
use URL;
use Twilio;
use Nexmo;
use Hash;

class LoginController extends Controller
{
	public function __construct()
    {
        $this->middleware('guest', ['except' => 'logout']);
    }

    public function username()
    {
		if (Request::isMethod('post')) {
			$formData = Request::all();
			$email = $formData['email'];
			$users = DB::table('sysusers')->where('Username', $email)->first();
			if(count($users)){
				$data['email'] = $users->email;
				$data['username'] = $users->Username;
				if($users->otp_auth == 1 && $users->bio_auth == 1){
					$data['finger_count'] = DB::table('demo_finger')->where('user_id', $users->UserID)->count();
					return view('login.login_type', $data);
				}else{
					$data['logintype'] = ($users->otp_auth == 1) ? 'otp_auth' : 'pswd_auth';
					return view('login.password', $data);					
				}
			}else{
				Request::session()->flash('flash_message', 'Username does not exist.');
				return redirect()->intended('username');
			}
		}
		return view('login.username');
		/* if($id == NULL){
			return view('login.username');
		}else{
			$data['id'] = $id;
			return view('login.login_type', $data);
		} */
    }
	
	public function logintype()
    {
		if (Request::isMethod('post')) {
			$formData = Request::all();
			$data['email'] = $formData['email'];
			$data['logintype'] = $formData['logintype'];
			return view('login.password', $data);
		}
		return redirect()->intended('username');
    }
	
	public function password()
    {
		if (Request::isMethod('post')) {
			$formData = Request::all();
			$email = $formData['email'];
			$password = $formData['password'];
			$users = DB::table('sysusers')->where('email', $email)->get();
			$id = 0;
			foreach($users AS $user){
				//if(Hash::check($password, $user->password)){
				if(md5($password) == $user->password){
					$id = $user->UserID;
					$phone = $user->mobile_no;
					break;
				}
			}

			if ($id) {
				if($formData['logintype'] == "pswd_auth"){
					Auth::loginUsingId($id);
					return redirect()->intended('home');
				}else{			
					$otp = mt_rand(1000, 9999);
					$message = "Business One login OTP: ".$otp;
					$response = Nexmo::message()->send([
						'to' => $phone,
						'from' => 'NEXMO',
						'text' => $message
					]);
					if(isset($response['status']) && $response['status'] == 1){
						Request::session()->flash('flash_message', 'Phone number is invalid or not registered.');
						return redirect()->intended('username');
					}
					//Twilio::message('+917906500917', $message);
					DB::table('sysusers')->where('UserID', $id)->update(['otp' => $otp, 'otp_generate_time' => time() ]);
					$data['user_id'] = $id;
					return view('login.one_time_pass', $data);
				}
			}else{
				$data['email'] = $email;
				$data['logintype'] = $formData['logintype'];
				Request::session()->flash('flash_message', 'Please enter correct password.');
				return view('login.password', $data);
			}
		}
		return redirect()->intended('username');
    }
	
	public function one_time_pass()
    {
		if (Request::isMethod('post')) {
			$formData = Request::all();
			$currtime = strtotime("- 5 minutes");
			$users = DB::table('sysusers')->where('UserID', $formData['user_id'])->where('otp', $formData['otp'])->first();
			if(count($users)){
				if($users->otp_generate_time < $currtime){
					Request::session()->flash('flash_message', 'Your OTP has been expired.');
					return redirect()->intended('username');
				}else if($formData['is_forgot']){
					return redirect()->intended('change_pass/'.base64_encode($formData['user_id']));
				}
				Auth::loginUsingId($formData['user_id']);
				return redirect()->intended('home');
			}else{
				$data['user_id'] = $formData['user_id'];
				$data['forgot_pass'] = $formData['is_forgot'];
				Request::session()->flash('flash_message', 'Please enter correct OTP.');
				return view('login.one_time_pass', $data);
			}
		}
		return view('login.username');
    }
	
	public function resend_otp(){
		if (Request::isMethod('post')) {
			$formData = Request::all();
		}
	}
	
	public function finger_varification($user_id){
		if($user_id == 0){
			echo "Invalid Parameters";
		}else{
			Auth::loginUsingId($user_id);
			return redirect()->intended('home');			
		}
	}
	
	public function ajax_action(){
		if(Request::isMethod('post')){
			$base_url = URL::to('/biomertic-login');
			$formData = Request::all();
			switch($formData['action']){
				case 'check_btn':
					$users = DB::table('sysusers')->where('Username', $formData['username'])->first();
					if(!empty($users) && $users->otp_auth == 0 && $users->bio_auth == 1){
						$finger_exist = DB::table('demo_finger')->where('user_id', $users->UserID)->count();
						$response['id'] = $users->UserID;
						$response['finger_exist'] = $finger_exist;
						if($finger_exist){
							$url_verification = base64_encode($base_url."/verification.php?user_id=".$users->UserID);
							$response['btn'] = "<a href='finspot:FingerspotVer;$url_verification' class='btn btn-success'>Login</a>";
						}else{
							$response['btn'] = '<button type="submit" class="btn btn-primary">Submit</button><div class="pull-right forgot-password"><a href="'.URL::to('/').'/forgot_pass">Forgot Your Password</a></div>';
						}
					}else{
						$response['id'] = 0;
					}
					echo json_encode($response);
				break;
				
				case 'checkreg':
					$finger_count = DB::table('demo_finger')->where('user_id', $formData['user_id'])->count();
					if (intval($finger_count) > intval($formData['current'])) {
						$response['result'] = true;			
						$response['current'] = intval($finger_count);			
					}else{
						$response['result'] = false;
					}
					echo json_encode($response);
				break;
				
				case 'btnontype':
					$users = DB::table('sysusers')->where('Username', $formData['username'])->first();
					$finger_exist = DB::table('demo_finger')->where('user_id', $users->UserID)->count();
					$response['id'] = $users->UserID;
					$response['finger_exist'] = $finger_exist;
					if($finger_exist){
						$url_verification = base64_encode($base_url."/verification.php?user_id=".$users->UserID);
						$response['btn'] = "<a href='finspot:FingerspotVer;$url_verification' class='btn btn-success'>Login</a>";
					}else{
						$url_register = base64_encode($base_url."/register.php?user_id=".$users->UserID);
						$response['btn'] = "<a href='finspot:FingerspotReg;$url_register' class='user-finger btn btn-primary' onclick=\"user_register_type('".$users->UserID."','".$users->Username."')\" finger-count = '$finger_exist'>Register</a>";
					}
					echo json_encode($response);
				break;
			}
		}
	}
	
	public function test(){
		return view('test');
	}
	
	
	
	
	/************************************************************************************************************/
	public function display_message(){
		echo "Invalid parameters"; die;
	}

	public function getac(){
		$formData = Request::all();
		$data = $this->getDeviceAcSn($formData['vc']);
		echo $data[0]['ac'].$data[0]['sn'];
	}
	
	public function process_register($user_id){
		$formData = Request::all();
		$data 		= explode(";",$formData['RegTemp']);
		$vStamp 	= $data[0];
		$sn 		= $data[1];
		$user_id	= $data[2];
		$regTemp 	= $data[3];
		$device = $this->getDeviceBySn($sn);
		$salt = md5($device[0]['ac'].$device[0]['vkey'].$regTemp.$sn.$user_id);
		
		if (strtoupper($vStamp) == strtoupper($salt)) {
			
			$sql1 		= "SELECT MAX(finger_id) as fid FROM demo_finger WHERE user_id=".$user_id;
			$result1 	= mysql_query($sql1);
			$data 		= mysql_fetch_array($result1);
			$fid 		= $data['fid'];
			DB::table('demo_finger')->insert([
				['user_id' => $user_id, 'finger_id' => 1, 'finger_data' => $regTemp]
			]);
			echo "empty";
		} else {
			return redirect()->intended('display_message');
		}
	}
	
	function getDevice() {
		$finger_count = DB::table('demo_device')->orderBy('device_name')->get();
		foreach($finger_count AS $fingercount){
			$arr[] = array(
				'device_name'	=> $fingercount->device_name,
				'sn'		=> $fingercount->sn,
				'vc'		=> $fingercount->vc,
				'ac'		=> $fingercount->ac,
				'vkey'		=> $fingercount->vkey
			);
		}
		return $arr;
	}
	
	function getDeviceAcSn($vc) {
		$finger_count = DB::table('demo_device')->where('vc', $vc)->get();
		foreach($finger_count AS $fingercount){
			$arr[] = array(
				'device_name'	=> $fingercount->device_name,
				'sn'		=> $fingercount->sn,
				'vc'		=> $fingercount->vc,
				'ac'		=> $fingercount->ac,
				'vkey'		=> $fingercount->vkey
			);
		}
		return $arr;
	}
	
	function getDeviceBySn($sn) {
		$finger_count = DB::table('demo_device')->where('sn', $sn)->get();
		foreach($finger_count AS $fingercount){
			$arr[] = array(
				'device_name'	=> $fingercount->device_name,
				'sn'		=> $fingercount->sn,
				'vc'		=> $fingercount->vc,
				'ac'		=> $fingercount->ac,
				'vkey'		=> $fingercount->vkey
			);
		}
		return $arr;
	}
	
	function getUser() {
		$finger_count = DB::table('sysusers')->where('bio_auth', 1)->orderBy('Username')->get();
		foreach ($finger_count AS $fingercount) {
			$arr[] = array(
				'user_id'	=> $fingercount->UserID,
				'user_name'	=> $fingercount->Username
			);
		}
		return $arr;
	}
	
	function deviceCheckSn($sn) {
		$finger_count = DB::table('demo_device')->where('sn', $sn)->count();
		if ($finger_count > 0) {
			return "sn already exist!";
		} else {
			return 1;
		}
	}
	
	function checkUserName($user_name) {
		$finger_count = DB::table('sysusers')->where('Username', $user_name)->count();
		if ($finger_count>0) {
			return "Username exist!";
		} else {
			return "1";
		}
	}
	
	function getUserFinger($user_id) {
		$finger_count = DB::table('demo_finger')->where('user_id', $user_id)->get();
		foreach($finger_count AS $fingercount) {
			$arr[] = array(
				'user_id'	=>$fingercount->user_id,
				"finger_id"	=>$fingercount->finger_id,
				"finger_data"	=>$fingercount->finger_data
				);
		}
		return $arr;
	}
	
	function getLog() {
		$finger_count = DB::table('demo_log')->orderBy('log_time', 'desc')->get();
		foreach ($finger_count AS $fingercount) {
			$arr[] = array(
				'log_time'		=> $fingercount->log_time,
				'user_name'		=> $fingercount->user_name,
				'data'			=> $fingercount->data
			);
		}
		return $arr;
	}
	
	function createLog($user_name, $time, $sn) {
		$tes = date('Y-m-d H:i:s', strtotime($time))." (PC Time) | ".$sn." (SN)";
		DB::table('demo_log')->insert([
			['user_name' => $user_name, 'data' => $tes]
		]);
		return 1;				
	}

	public function forgot_pass()
    {
		if (Request::isMethod('post')) {
			$formData = Request::all();
			$username = $formData['username'];
			$users = DB::table('sysusers')->where('Username', $username)->first();
			if(count($users)){
				$id = $users->UserID;
				$phone = $users->mobile_no;
				$data['email'] = $users->email;
				$data['username'] = $users->Username;
				$otp = mt_rand(1000, 9999);
				$message = "Business One Forgot Password OTP: ".$otp;
				$response = Nexmo::message()->send([
					'to' => $phone,
					'from' => 'NEXMO',
					'text' => $message
				]);
				if(isset($response['status']) && $response['status'] == 1){
					Request::session()->flash('flash_message', 'Phone number is invalid or not registered.');
					return redirect()->intended('forgot_pass');
				}
				DB::table('sysusers')->where('UserID', $id)->update(['otp' => $otp, 'otp_generate_time' => time() ]);
				$data['user_id'] = $id;
				$data['forgot_pass'] = 1;
				return view('login.one_time_pass', $data);
			}else{
				Request::session()->flash('flash_message', 'Username does not exist.');
				return redirect()->intended('forgot_pass');
			}
		}
		return view('login.forgot_pass');
	}
	
	public function change_pass($user_id = NULL){
		if (Request::isMethod('post')) {
			$formData = Request::all();
			if($formData['password'] != $formData['confirm_password']){
				Request::session()->flash('flash_message', 'Password and confirm password does not match.');
				$user_id = base64_encode($formData['user_id']);
				return redirect()->intended('change_pass/'.$user_id);
			}else{
				DB::table('sysusers')->where('UserID', $formData['user_id'])->update(['password' => md5($formData['password'])]);
				Request::session()->flash('flash_message', 'Password has been updated.');
				Request::Session()->flash('alert-class', 'alert-success');
				$user_id = base64_encode($formData['user_id']);
				return redirect()->intended('username');
			}
		}
		$data['user_id'] = base64_decode($user_id);
		return view('login.change_pass', $data);
	}
}
