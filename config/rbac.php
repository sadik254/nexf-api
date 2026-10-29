<?php

$permissions = [
'dashboard.view'=>['admin','seller','seller_staff','reseller'],'analytics.view'=>['admin','seller','seller_staff'],'commission.view'=>['reseller'],
'orders.view'=>['admin','seller','seller_staff'],'orders.manage'=>['admin','seller','seller_staff'],'payments.view'=>['admin'],'payments.confirm'=>['admin'],'payments.delete'=>['admin'],'returns.manage'=>['admin','seller','seller_staff'],'fraud.check'=>['admin','seller','seller_staff'],'fraud_guard.manage'=>['admin','seller','seller_staff'],
'products.view'=>['admin','seller','seller_staff','reseller'],'products.edit'=>['admin','seller','seller_staff'],'products.delete'=>['admin','seller'],'inventory.adjust'=>['admin','seller','seller_staff'],'taxonomy.manage'=>['admin'],'collections.manage'=>['admin','seller','seller_staff'],'size_charts.manage'=>['admin','seller','seller_staff'],'media.manage'=>['admin','seller','seller_staff'],
'discounts.manage'=>['admin','seller','seller_staff'],'offers.review'=>['admin'],'stores.view'=>['admin'],'stores.manage'=>['admin'],'stores.delete'=>['admin'],'store.profile'=>['seller'],'staff.manage'=>['admin','seller'],'resellers.manage'=>['admin'],'customers.view'=>['admin'],'customers.manage'=>['admin'],'admins.manage'=>[],
'support.reply'=>['admin','seller','seller_staff','reseller'],'chats.reply'=>['admin','seller','seller_staff'],'reviews.moderate'=>['admin'],'reviews.reply'=>['admin','seller','seller_staff'],'questions.moderate'=>['admin'],'questions.answer'=>['admin','seller','seller_staff'],'reports.action'=>['admin'],'withdrawals.settle'=>['admin'],
'storefront.edit'=>[],'store_layout.edit'=>['admin','seller','seller_staff'],'site_info.edit'=>[],'delivery_charges.edit'=>['admin','seller','seller_staff'],'payment_methods.manage'=>[],'shipping_methods.manage'=>[],'rewards.manage'=>[],'commission.edit'=>['admin'],
];
$all = array_keys($permissions);
return [
 'permissions'=>$permissions,
 'defaults'=>[
  'admin'=>array_values(array_filter($all, fn($p)=>in_array('admin',$permissions[$p],true) && !in_array($p,['storefront.edit','site_info.edit','payment_methods.manage','shipping_methods.manage','rewards.manage'],true))),
  'seller'=>array_values(array_filter($all, fn($p)=>in_array('seller',$permissions[$p],true))),
  'seller_staff'=>array_values(array_filter($all, fn($p)=>in_array('seller_staff',$permissions[$p],true))),
  'reseller'=>['dashboard.view','commission.view','products.view','support.reply'],
 ],
];
