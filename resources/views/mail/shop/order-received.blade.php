<x-mail::message>
# 新しい注文が入りました

@include('mail.shop._summary')

@unless ($order->hasDigital() && ! $order->hasPhysical())
お届け先: {{ $order->shipping_name }}
@endunless

<x-mail::button :url="route('creator.orders.show', $order)">
注文を確認する
</x-mail::button>
</x-mail::message>
