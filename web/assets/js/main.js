(function () {
    'use strict';

    var header = document.querySelector('.site-header');
    var toggle = document.querySelector('.nav-toggle');
    var nav = document.getElementById('nav');

    // Zaglavlje postaje puno nakon malo skrolanja (na naslovnici je prozirno preko videa).
    function onScroll() {
        header.classList.toggle('is-solid', window.scrollY > 40);
    }
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    if (toggle && nav) {
        toggle.addEventListener('click', function () {
            var open = nav.classList.toggle('is-open');
            header.classList.toggle('menu-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    // Hero video: 720p za uske zaslone i ustedu podataka, 1080p inace.
    // Bez JavaScripta ili uz "smanjeno kretanje" ostaje poster.
    var video = document.querySelector('.hero video[data-src-1080]');
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var saveData = navigator.connection && navigator.connection.saveData;

    if (video && !reduced && !saveData) {
        var small = window.matchMedia('(max-width: 900px)').matches;
        if (!small && video.canPlayType('video/webm; codecs="vp9"')) {
            var webm = document.createElement('source');
            webm.src = video.dataset.srcWebm;
            webm.type = 'video/webm';
            video.appendChild(webm);
        }
        var mp4 = document.createElement('source');
        mp4.src = small ? video.dataset.src720 : video.dataset.src1080;
        mp4.type = 'video/mp4';
        video.appendChild(mp4);
        video.load();
        var p = video.play();
        if (p && p.catch) { p.catch(function () {}); }

        var vt = document.querySelector('.video-toggle');
        if (vt) {
            vt.hidden = false;
            vt.addEventListener('click', function () {
                var paused = !video.paused;
                if (paused) { video.pause(); } else { video.play(); }
                vt.querySelector('span').textContent = paused ? 'Pokreni' : 'Pauza';
                vt.querySelector('use').setAttribute('href', paused ? '#i-play' : '#i-pause');
            });
        }
    }

    // Film "Pogledajte nasu pricu" — ucitava se tek na klik.
    var modal = document.getElementById('film');
    if (modal) {
        var film = modal.querySelector('video');
        var lastFocus = null;
        function openFilm() {
            lastFocus = document.activeElement;
            if (!film.src) { film.src = film.dataset.src; }
            modal.classList.add('is-open');
            film.play();
            modal.querySelector('.modal-close').focus();
        }
        function closeFilm() {
            film.pause();
            modal.classList.remove('is-open');
            if (lastFocus) { lastFocus.focus(); }
        }
        document.querySelectorAll('[data-film]').forEach(function (b) { b.addEventListener('click', openFilm); });
        modal.querySelector('.modal-close').addEventListener('click', closeFilm);
        modal.addEventListener('click', function (e) { if (e.target === modal) { closeFilm(); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('is-open')) { closeFilm(); } });
    }

    // Blagi ulazak sekcija pri skrolanju.
    if ('IntersectionObserver' in window && !reduced) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); }
            });
        }, { threshold: .12 });
        document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });
    } else {
        document.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('is-in'); });
    }

    // Kontakt obrazac -> send.php. Bez JavaScripta obrazac se salje obicno,
    // a send.php vraca na kontakt.html?poslano=1|0.
    var form = document.querySelector('form[data-ajax]');
    if (form) {
        var msg = form.querySelector('.form-msg');
        var btn = form.querySelector('button[type="submit"]');
        var t = form.querySelector('input[name="t"]');
        if (t) { t.value = Date.now(); }

        function show(ok, text) {
            msg.textContent = text;
            msg.classList.toggle('is-error', !ok);
            msg.style.display = 'block';
        }

        var q = new URLSearchParams(location.search).get('poslano');
        if (q === '1') { show(true, 'Hvala! Vaš upit je poslan, javit ćemo vam se u najkraćem roku.'); }
        if (q === '0') { show(false, 'Poruka nije poslana. Provjerite polja ili nas nazovite na +385 32 550 399.'); }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!form.reportValidity()) { return; }
            btn.disabled = true;
            fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    show(res.ok, res.message);
                    if (res.ok) { form.reset(); if (t) { t.value = Date.now(); } }
                })
                .catch(function () {
                    show(false, 'Poruku trenutno nije moguće poslati. Nazovite nas na +385 32 550 399 ili pišite na cezareja@cezareja.hr.');
                })
                .then(function () { btn.disabled = false; });
        });
    }
})();
