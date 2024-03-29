<?php
/**
 * Created By PhpStorm
 * User azad
 * Date 11/2/2022
 * Time 10:48 AM
 * To change this template use File || Settings || File and Code Templates
 */

namespace frontend\controllers;

use common\models\CartItem;
use common\models\Order;
use common\models\OrderAddress;
use common\models\Product;
use common\models\User;
use common\widgets\Alert;
use http\Client;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Orders\OrdersGetRequest;
use PayPalCheckoutSdk\Payments\AuthorizationsGetRequest;
use Sample\PayPalClient;
use Yii;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\helpers\VarDumper;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class CartController extends \frontend\base\Controller
{
    public function behaviors()
    {
        return [
              [
                  'class' => ContentNegotiator::class,
                  'only'=> ['add','create-order','submit-payment','change-quantity'],
                  'formats' => [
                      'application/json' => Response::FORMAT_JSON
                  ]
              ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete' => ['POST', 'DELETE'],
                    'create-order' => ['POST'],
                ],
            ],
        ];
    }

    public function actionIndex(){

        $cartItems = CartItem::getItemsForUser(currUserId());

        return $this->render('index',[
                'items' => $cartItems
            ]);
    }

    public function actionAdd(){
       $id = \Yii::$app->request->post('id');
       $product = Product::find()->id($id)->published()->one();
       if(!$product){
           throw new NotFoundHttpException('Product dose not exist');
       }

       if(isGuest()){
           //Get cart items from session
            $cartItems = \Yii::$app->session->get(CartItem::SESSION_KEY, []);
            $found = false;
            foreach ($cartItems as &$item){
                if($item['id'] == $id){
                    $item['quantity']++;
                    $found = true;
                    break;
                }
            }
            if(!$found){
                $cartItem = [
                    'id' => $id,
                    'name' => $product->name,
                    'image' => $product->image,
                    'price' => $product->price,
                    'quantity' => 1,
                    'totalPrice' => $product->price
                ];
                $cartItems[] = $cartItem;
            }

            \Yii::$app->session->set(CartItem::SESSION_KEY, $cartItems);
       }else{
           $userId = \Yii::$app->user->id;
           $cartItem = CartItem::find()->userId($userId)->productId($id)->one();
           if($cartItem){
               $cartItem->quantity++;
           }else{
               $cartItem = new CartItem();
               $cartItem->product_id = $id;
               $cartItem->quantity = 1;
               $cartItem->created_by = $userId;
           }
           if($cartItem->save()){
               return [
                   'success' => true
               ];
           }else{
               return [
                   'success' => false,
                   'errors' => $cartItem->errors
               ];
           }
       }
        return [
            'success' => true
        ];
    }

    public function actionDelete($id)
    {
        if(isGuest()){
            $cartItems = \Yii::$app->session->get(CartItem::SESSION_KEY, []);
            foreach ($cartItems as $i => $cartItem){
                if($cartItem['id'] == $id){
                    array_splice($cartItems, $i, 1);
                    break;
                }
            }
            \Yii::$app->session->set(CartItem::SESSION_KEY, $cartItems);
        }else{
            CartItem::deleteAll(['product_id' =>$id, 'created_by' => currUserId()]);
        }
        return $this->redirect(['cart/index']);
    }


    public function actionChangeQuantity(){
        $id = \Yii::$app->request->post('id');
        $product = Product::find()->id($id)->published()->one();
        if(!$product){
            throw new NotFoundHttpException('Product dose not exist');
        }
        $quantity = \Yii::$app->request->post('quantity');
        if(isGuest()) {
            $cartItems = \Yii::$app->session->get(CartItem::SESSION_KEY, []);
            foreach ($cartItems as &$cartItem) {
                if ($cartItem['id'] == $id) {
                    $cartItem['quantity'] = $quantity;
                    $cartItem['totalPrice'] = $quantity * $cartItem['price'];
                    break;
                }
            }
            \Yii::$app->session->set(CartItem::SESSION_KEY, $cartItems);
        }else{
            $cartItem = CartItem::find()->userId(currUserId())->productId($id)->one();
            if($cartItem){
                $cartItem->quantity = $quantity;
                $cartItem->save();
            }
        }

        return [
          'quantity' => CartItem::getTotalQuantityForUser(currUserId()),
          'itemTotalprice' =>  Yii::$app->formatter->asCurrency(CartItem::getTotalPriceForItemForUser($id,currUserId()))
        ];
    }

    public function actionCheckout(){
        $cartItems = CartItem::getItemsForUser(currUserId());
        $productQuantity = CartItem::getTotalQuantityForUser(currUserId());
        $totalPrice = CartItem::getTotalPriceForUser(currUserId());

        if(empty($cartItems)){
            return $this->redirect(Yii::$app->homeUrl);
        }

        $order = new Order();
        $order->total_price = $totalPrice;
        $order->status = Order::STATUS_DRAFT;
        $order->created_at = time();
        $order->created_by = currUserId();

        $transaction = Yii::$app->db->beginTransaction();

        if($order->load(Yii::$app->request->post())
            && $order->save()
            && $order->saveAddress(Yii::$app->request->post())
            && $order->saveOrderItems()){

            $transaction->commit();

            CartItem::clearCartItems(currUserId());

            return $this->render('pay-now',[
                'order' => $order
            ]);
        }
        $orderAddress = new OrderAddress();

        if(!isGuest()) {
            /** @var User $user */
            $user = Yii::$app->user->identity;
            $userAddress = $user->getAddress();

            $order->firstname = $user->firstname;
            $order->lastname = $user->lastname;
            $order->email = $user->email;
            $order->status = Order::STATUS_DRAFT;

            $orderAddress->address = $userAddress->address;
            $orderAddress->city = $userAddress->city;
            $orderAddress->state = $userAddress->state;
            $orderAddress->country = $userAddress->country;
            $orderAddress->zipcode = $userAddress->zipcode;
        }

        return $this->render('checkout', [
            'order' => $order,
            'orderAddress' => $orderAddress,
            'cartItems' => $cartItems,
            'productQuantity' => $productQuantity,
            'totalPrice' =>$totalPrice
        ]);
    }


    public function actionSubmitPayment($orderId){

        $where = ['id' => $orderId, 'status' => Order::STATUS_DRAFT];
        if(!isGuest()){
            $where['created_by'] = currUserId();
        }
        $order = Order::findOne($where);
        if(!$order){
            throw new NotFoundHttpException();
        }

        $req = Yii::$app->request;
        $paypalOrderId = $req->post('orderId');
        $exists = Order::find()->andWhere(['paypal_orderId' => $paypalOrderId])->exists();
        if($exists){
            throw new BadRequestHttpException();
        }

        $environment = new SandboxEnvironment(Yii::$app->params['paypalClientId'], Yii::$app->params['paypalSecret']);
        $client = new PayPalHttpClient($environment);

        $response = $client->execute(new OrdersGetRequest($paypalOrderId));

        if($response->statusCode === 200){
            $order->paypal_orderId = $paypalOrderId;
            $paidAmount = 0;
            foreach ($response->result->purchase_units as $purchase_unit){
                if($purchase_unit->amount->currency_code === 'USD'){
                    $paidAmount += $purchase_unit->amount->value;
                }
            }

            if($paidAmount === (float)$order->total_price && $response->result->status === 'COMPLETED'){
                $order->status = Order::STATUS_PAID;
            }
            $order->transaction_id = $response->result->purchase_units[0]->payments->captures[0]->id;
            if($order->save()){
                if(!$order->sendEmailToVendor()){
                    Yii::error("Email to the vendor is not sent");
                }
                if(!$order->sendEmailToCustomer()){
                    Yii::error("Email to the customer is not sent");
                }

                return [
                    'success' => true
                ];
            }else{
                Yii::error("Order was not saved. Data: ".VarDumper::dumpAsString($order->toArray()).
                    '.Errors: '.VarDumper::dumpAsString($order->errors));
            }
        }

        throw new BadRequestHttpException();
        /*echo '<pre>';
        var_dump($response);
        echo '</pre>';*/

    }

}