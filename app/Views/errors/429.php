<div class="container py-20 lg:py-28 max-w-2xl text-center">
    <p class="eyebrow">Error 429</p>
    <h1 class="!text-3xl lg:!text-4xl !mt-3">Too many attempts</h1>
    <p class="lead mx-auto mt-4">You have made a lot of requests in a short time. Please wait a minute and try again.</p>
    <div class="mt-8 flex flex-wrap justify-center gap-3">
        <a href="<?= e(route('home')) ?>" class="btn">Back to home</a>
        <a href="<?= e(route('shop')) ?>" class="btn btn--outline">Continue shopping</a>
    </div>
</div>
