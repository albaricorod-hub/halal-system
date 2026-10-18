<?php
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About & Halal Traceability - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-stone-50 text-stone-800">
    <?php include 'navbar.php'; ?>
    <main class="mx-auto max-w-5xl px-4 py-10">
        <header class="hh-page-intro rounded-3xl border border-emerald-100 px-6 py-10 sm:px-10">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">About Halal Heritage</p>
            <h1 class="mt-3 text-3xl font-extrabold text-emerald-950 sm:text-4xl">Food, culture, and clearer sourcing</h1>
            <p class="mt-4 max-w-3xl leading-relaxed text-stone-700">Halal Heritage is an online food ordering and culinary heritage platform. It brings traditional dishes and supplier-listed foods together so customers can explore their stories, review available halal and source information, and place orders.</p>
        </header>

        <section class="mt-7 rounded-3xl border border-stone-200 bg-white p-6 shadow-sm sm:p-8">
            <div class="flex items-start gap-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-emerald-100 text-xl text-emerald-900" aria-hidden="true">✦</span>
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-800">Digital halal certifications & tracking tags</p>
                    <h2 class="mt-2 text-2xl font-extrabold text-emerald-950">Check the details before you buy</h2>
                    <p class="mt-3 leading-relaxed text-stone-600">Product listings show a traceability tag with a supplier certificate reference when one has been provided, together with the available origin and slaughter-method information. If a detail has not been supplied, the listing says so rather than guessing.</p>
                </div>
            </div>
            <div class="mt-6 grid gap-4 sm:grid-cols-3">
                <article class="rounded-2xl bg-stone-50 p-4">
                    <h3 class="font-bold text-emerald-950">Certificate reference</h3>
                    <p class="mt-2 text-sm leading-relaxed text-stone-600">A certificate ID on a listing is a reference supplied by the partner, not a claim that Halal Heritage has independently authenticated its issuer or current validity.</p>
                </article>
                <article class="rounded-2xl bg-stone-50 p-4">
                    <h3 class="font-bold text-emerald-950">Origin & sourcing</h3>
                    <p class="mt-2 text-sm leading-relaxed text-stone-600">Origin details come from the information available for the food or supplier. A regional or heritage origin may not identify a specific farm.</p>
                </article>
                <article class="rounded-2xl bg-stone-50 p-4">
                    <h3 class="font-bold text-emerald-950">Slaughter method</h3>
                    <p class="mt-2 text-sm leading-relaxed text-stone-600">The method is shown only when it is stated in the supplier's halal information. Otherwise, it is marked as not specified.</p>
                </article>
            </div>
            <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-relaxed text-amber-950"><strong>For your assurance:</strong> Review the supplier and certificate reference shown on each product, and contact the supplier or relevant certification body if you need confirmation before purchasing.</p>
            <a href="menu.php" class="mt-6 inline-flex rounded-xl bg-emerald-800 px-5 py-3 text-sm font-bold text-white transition hover:bg-emerald-700">Explore the menu</a>
        </section>
    </main>
</body>
</html>