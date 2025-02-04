<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Investment;
use Carbon\Carbon;
class InvestmentController extends APIController
{


    public $requestClass = 'App\Http\Controllers\RequestMoneyController';
    public $ledgerClass = 'App\Http\Controllers\LedgerController';
    public $notificationClass = 'App\Http\Controllers\NotificationSettingController';
    function __construct(){
      $this->model = new Investment();
      $this->notRequired = array(
        'message'
      );
    }

    public function create(Request $request){
      $response = array(
        'data'  => null,
        'otp'   => null,
        'error' => null,
        'timestamps' => Carbon::now()
      );
      $data = $request->all();
      $amount = floatval($data['amount']);
      $remainingAmount = app($this->requestClass)->getAmount($data['request_id']);
      $invested = $this->invested($data['request_id']);
      $remainingAmount = ($remainingAmount) ? ($remainingAmount - $invested['total']) : null;
      $myBalance = floatval(app($this->ledgerClass)->retrievePersonal($data['account_id']));
      if($myBalance < $amount){
        $response['error'] = 'You have insufficient balance. Your balance is PHP '.$myBalance.' balance.';
      }else if($remainingAmount){
        if($remainingAmount < $amount){
          $response['error'] = 'Remaining amount is less than the invested amount. Refresh and adjust your investment now.';
        }else{
          $left = $remainingAmount - $amount;
          if($left < floatval($data['minimum']) && $left > 0){
            $response['error'] = 'Remaining amount should not be less than the minimum investment amount';
          }else{
            // make investment here.
            if($data['otp'] == 0){
              // request
              $code = app($this->notificationClass)->generateOTPFundTransfer($data['account_id']);
              $response['otp'] = true;
            }else if($data['otp'] == 1){
              $invest = new Investment();
              $invest->code = $this->generateCode();
              $invest->account_id = $data['account_id'];
              $invest->request_id = $data['request_id'];
              $invest->amount = $amount;
              $invest->message = $data['message'];
              $invest->created_at = Carbon::now();
              $invest->save();
              $response['data'] = $invest->id;
              $response['error'] = null;
              $description = 'Invested to';
              $payload = 'investments';
              $payloadValue = $invest->id;
              app($this->ledgerClass)->addToLedger($data['account_id'], $amount * (-1), $description, $payload, $payloadValue);
              if($left <= 0){
                app($this->requestClass)->updateStatus($data['request_id']);
              }
            }
          }
        }
      }else{
        $response['error'] = 'I\'m sorry the request was already approved.';
      }

      return response()->json($response);
    }

    public function generateCode(){
      $code = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 32);
      $codeExist = Investment::where('code', '=', $code)->get();
      if(sizeof($codeExist) > 0){
        $this->generateCode();
      }else{
        return $code;
      }
    }

    public function retrieve(Request $request){
      $data = $request->all();

      $this->retrieveDB($data);

      $result = $this->response['data'];
      if(sizeof($result) > 0){
        $i = 0;
        foreach ($result as $key) {
          $this->response['data'][$i]['created_at_human'] = Carbon::createFromFormat('Y-m-d H:i:s', $result[$i]['created_at'])->copy()->tz('Asia/Manila')->format('F j, Y');
          $requests = app($this->requestClass)->retrieveById($result[$i]['request_id']);
          $this->response['data'][$i]['request'] = $requests;
          $amount = floatval($result[$i]['amount']);
          $interest = intval($requests['interest']);
          $returnPerMonth = floatval($amount * ($interest / 100));
          $this->response['data'][$i]['return_per_month'] = $returnPerMonth;
          $i++;
        }
      }

      return $this->response();
    }

    public function retrieveById($id){
      $result = Investment::where('id', '=', $id)->get();

      return sizeof($result) > 0 ? $result[0] : null;
    }

    public function getRequest(){}

    public function invested($requestId){
      $total = 0;
      $i = 0;
      $result = Investment::where('request_id', '=', $requestId)->get();
      if (sizeof($result) > 0) {
        foreach ($result as $key) {
          $total += floatval($result[$i]['amount']);
          $i++;
        }
      }
      return array(
        'total' => $total,
        'size' => sizeof($result)
      );
    }

    public function approved(){
      $result = Investment::sum('amount');
      return $result;
    }
}
