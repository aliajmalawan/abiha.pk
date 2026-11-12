<?php

/*
|--------------------------------------------------------------------------
| FAQ page (/pages/faq)
|--------------------------------------------------------------------------
| Every answer describes how this website actually works. Anything that depends on the shop's own terms (delivery times, the return
| window, charges, bank details) points to the page or setting where the owner states it, instead of inventing a number.
|
| Tokens inside an answer become links or live values:
|   {payments}  the payment methods that are switched on right now      {shipping}  Shipping & returns page
|   {track}     Track your order page                                    {contact}   Contact page
|   {login}     Sign in page                                             {register}  Create account page
|   {privacy}   Privacy policy page                                      {forgot}    Forgot-password page
|   {phone}, {email}  the shop's phone / email from Admin → Settings (the sentence is left out when they are empty)
|
| To replace the whole page with your own text, edit the FAQ page under Admin → Pages and untick "Sample"; your text is then shown instead.
*/
return [
    'groups' => [
        'Ordering' => [
            ['q' => 'Do I need an account to place an order?',
             'a' => 'No. You can check out as a guest with your name, phone number and delivery address. An account lets you save addresses, follow all your orders in one place and keep a wishlist. You can {register} at any time.'],
            ['q' => 'How do I place an order?',
             'a' => 'Add your pieces to the bag, open the bag to review them, then continue to checkout. Enter your delivery details, choose how you would like to pay and confirm. You will land on an order confirmation page with your order number.'],
            ['q' => 'Can I change or cancel my order?',
             'a' => 'You can cancel it yourself from My account → Orders as long as it has not been shipped and has not been paid. After that, or if you want to change the size, colour or address, please {contact} as soon as possible with your order number.'],
            ['q' => 'How do I use a discount code?',
             'a' => 'Type the code in the box in your bag and press Apply. Some codes have a minimum order or apply only to chosen categories or products; the discount is then worked out on the eligible items only. The code is checked again when you place the order.'],
            ['q' => 'What if an item is sold out?',
             'a' => 'Sold-out items are marked on the product page and cannot be added to the bag. Tap the heart to save it to your wishlist and check back later.'],
        ],
        'Payment' => [
            ['q' => 'Which payment methods can I use?',
             'a' => 'Right now: {payments}. The methods available to you are always shown at checkout, so that is the most up-to-date list.'],
            ['q' => 'How does bank transfer work?',
             'a' => 'Choose bank transfer at checkout and you will see our account details. Transfer the exact order amount, then add your payment reference or upload the receipt on your order page. We compare it with our bank statement and mark the order as paid. An order is never marked as paid automatically.'],
            ['q' => 'How does cash on delivery work?',
             'a' => 'Pay the courier in cash when your parcel arrives. Please keep the exact amount ready. The order is marked as paid once the cash has reached us.'],
            ['q' => 'Is my personal information safe?',
             'a' => 'We only ask for what is needed to deliver your order and look after your account. Please read our {privacy} to see what we collect and how it is used.'],
        ],
        'Shipping & delivery' => [
            ['q' => 'How much does delivery cost?',
             'a' => 'The delivery charge depends on your city and province and is shown at checkout before you confirm, so there are no surprises. If a free-delivery offer applies to your order, you will see it in your bag.'],
            ['q' => 'How long will delivery take?',
             'a' => 'Estimated delivery times for each delivery method are listed on our {shipping} page and shown at checkout. Delivery times are estimates and can be affected by your location and the courier.'],
            ['q' => 'How can I track my order?',
             'a' => 'Use {track} with your order number and the email address you used at checkout. If you have an account, your order page also shows the status. Once your parcel is shipped, the courier name and tracking number we entered appear there too.'],
            ['q' => 'Which areas do you deliver to?',
             'a' => 'Enter your province, city and address at checkout: the delivery methods and the charge for your address are shown before you pay. More about delivery is on our {shipping} page. If you are unsure whether we can reach your area, {contact} first.'],
        ],
        'Returns & refunds' => [
            ['q' => 'What is your returns policy?',
             'a' => 'Our returns policy, including how long you have and the condition items must be in, is written on our {shipping} page. Please read it before you send anything back.'],
            ['q' => 'How do I return or exchange an item?',
             'a' => 'Please {contact} with your order number and the item you want to return or exchange. We will tell you the next steps. Please do not send a parcel back before you hear from us.'],
            ['q' => 'When will I get my refund?',
             'a' => 'Once the returned goods have reached us and have been checked, we process the refund as set out in our return policy on the {shipping} page.'],
        ],
        'Your account' => [
            ['q' => 'I forgot my password. What do I do?',
             'a' => 'Use the {forgot} page and enter your email address. We will send you a link to choose a new password.'],
            ['q' => 'Where can I see my past orders?',
             'a' => 'Sign in and open My account → Orders. Orders you placed as a guest can be followed with {track}.'],
            ['q' => 'How do I leave a review?',
             'a' => 'Open the product page, sign in and write your review. Reviews are checked by our team before they appear on the site.'],
        ],
        'Help & contact' => [
            ['q' => 'How can I contact ABIHA?',
             'a' => 'Send us a message through our {contact} page. {phone} {email}'],
            ['q' => 'Where do I find the size guide?',
             'a' => 'Where we have one for a product, the size guide is on that product page. If you are unsure about a fit, {contact} before you order and we will help.'],
        ],
    ],
];
