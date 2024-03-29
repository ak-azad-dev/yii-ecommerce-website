<?php

namespace common\models;

use Yii;

/**
 * This is the model class for table "{{%cart_items}}".
 *
 * @property int $id
 * @property int $product_id
 * @property int $quantity
 * @property int|null $created_by
 *
 * @property User $createdBy
 * @property Product $product
 */
class CartItem extends \yii\db\ActiveRecord
{
    const SESSION_KEY = 'CART_ITEMS';

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%cart_items}}';
    }

    public static function getTotalQuantityForUser($currUserId)
    {
        if(isGuest()){
            $cartItems = \Yii::$app->session->get(CartItem::SESSION_KEY, []);
            $sum = 0;
            foreach ($cartItems as $cartItem){
                $sum += $cartItem['quantity'];
            }
        }else{
            $sum = CartItem::findBySql("
                            SELECT 
                                SUM(quantity) 
                            FROM cart_items 
                            WHERE created_by = :userId",
                ['userId' => $currUserId]
            )->scalar();
        }
        return $sum;
    }


    public static function getItemsForUser(?int $currUserId)
    {
        if (isGuest()) {
            //Get cart items from session
            $cartItems = Yii::$app->session->get(CartItem::SESSION_KEY, []);
        } else {
            $cartItems = CartItem::findBySql(
                "SELECT
                    a.product_id as id
                    ,p.image
                    ,p.name
                    ,p.price
                    ,a.quantity
                    ,p.price * a.quantity as totalPrice
                FROM cart_items a
                LEFT JOIN products p on p.id = a.product_id 
                WHERE a.created_by = :userId",
                ['userId' => $currUserId])
                ->asArray()
                ->all();
        }
        return $cartItems;
    }

    public static function clearCartItems(?int $currUserId)
    {
        if (isGuest()) {
            //Delete cart items from session
            Yii::$app->session->remove(CartItem::SESSION_KEY);
        } else {
            CartItem::deleteAll(['created_by' => $currUserId]);
        }
    }

    public static function getTotalPriceForUser(?int $currUserId)
    {
        if(isGuest()){
            $cartItems = \Yii::$app->session->get(CartItem::SESSION_KEY, []);
            $sum = 0;
            foreach ($cartItems as $cartItem){
                $sum += $cartItem['quantity'] * $cartItem['price'];
            }
        }else{
            $sum = CartItem::findBySql("
                            SELECT 
                                SUM(p.price * a.quantity)
                            FROM cart_items a
                            LEFT JOIN products p on p.id = a.product_id 
                            WHERE a.created_by = :userId",
                ['userId' => $currUserId]
            )->scalar();
        }
        return $sum;
    }


    public static function getTotalPriceForItemForUser($productId, $currUserId)
    {
        if(isGuest()){
            $cartItems = \Yii::$app->session->get(CartItem::SESSION_KEY, []);
            $sum = 0;
            foreach ($cartItems as $cartItem){
                if($cartItem['id'] == $productId){
                    $sum += $cartItem['quantity'] * $cartItem['price'];
                }

            }
        }else{
            $sum = CartItem::findBySql("
                            SELECT 
                                SUM(p.price * a.quantity)
                            FROM cart_items a
                            LEFT JOIN products p on p.id = a.product_id 
                            WHERE a.product_id = :id AND a.created_by = :userId",
                ['id' => $productId,'userId' => $currUserId]
            )->scalar();
        }
        return $sum;
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['product_id', 'quantity'], 'required'],
            [['product_id', 'quantity', 'created_by'], 'integer'],
            [['created_by'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['created_by' => 'id']],
            [['product_id'], 'exist', 'skipOnError' => true, 'targetClass' => Product::class, 'targetAttribute' => ['product_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'product_id' => 'Product ID',
            'quantity' => 'Quantity',
            'created_by' => 'Created By',
        ];
    }

    /**
     * Gets query for [[CreatedBy]].
     *
     * @return \yii\db\ActiveQuery|\common\models\query\UserQuery
     */
    public function getCreatedBy()
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    /**
     * Gets query for [[Product]].
     *
     * @return \yii\db\ActiveQuery|\common\models\query\ProductQuery
     */
    public function getProduct()
    {
        return $this->hasOne(Product::class, ['id' => 'product_id']);
    }

    /**
     * {@inheritdoc}
     * @return \common\models\query\CartItemQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new \common\models\query\CartItemQuery(get_called_class());
    }
}
