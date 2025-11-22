</div>

<footer class="site-footer">
    <div class="container footer-inner">
        <div class="footer-copy">© <?= date('Y') ?> <?= h(APP_NAME) ?> · Hand-made with love.</div>

        <nav class="social">
            <a class="yt" href="https://www.youtube.com/@ava-d"
               target="_blank" rel="noopener nofollow"
               aria-label="Ava D on YouTube">
                <!-- YouTube icon -->
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M23.498 6.186a3.002 3.002 0 0 0-2.112-2.123C19.3 3.5 12 3.5 12 3.5s-7.3 0-9.386.563A3.002 3.002 0 0 0 .502 6.186C0 8.283 0 12 0 12s0 3.717.502 5.814a3.002 3.002 0 0 0 2.112 2.123C4.7 20.5 12 20.5 12 20.5s7.3 0 9.386-.563a3.002 3.002 0 0 0 2.112-2.123C24 15.717 24 12 24 12s0-3.717-.502-5.814ZM9.75 15.5v-7l6 3.5-6 3.5Z"/>
                </svg>
                <span>@ava-d</span>
            </a>
            <a class="ig" href="https://www.instagram.com/avadolewski"
               target="_blank" rel="noopener nofollow"
               aria-label="Ava on Instagram">
                <!-- Instagram icon -->
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.07 1.646.07 4.85s-.012 3.584-.07 4.85c-.148 3.225-1.664 4.771-4.919 4.919-1.266.058-1.646.07-4.85.07s-3.584-.012-4.85-.07c-3.252-.148-4.771-1.691-4.919-4.919-.058-1.265-.07-1.646-.07-4.85s.012-3.584.07-4.85c.148-3.225 1.664-4.771 4.919-4.919C8.416 2.175 8.796 2.163 12 2.163m0-2.163C8.74 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.74 0 12s.014 3.667.072 4.947c.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.74 24 12 24s3.667-.014 4.947-.072c4.358-.2 6.78-2.618 6.98-6.98.058-1.28.072-1.687.072-4.947s-.014-3.667-.072-4.947C21.728 2.69 19.308.272 14.947.072 13.667.014 13.26 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.88 1.44 1.44 0 000-2.88z"/>
                </svg>
                <span>@avadolewski</span>
            </a>
            <a class="email" href="mailto:ava@avadcreates.com"
               aria-label="Contact via Email">
                <!-- Email icon -->
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M22 6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6zm-2 0l-8 5-8-5h16zm0 12H4V8l8 5 8-5v10z"/>
                </svg>
                <span>Email</span>
            </a>
        </nav>
    </div>
</footer>

<style>
    .site-footer{margin-top:40px;padding:22px 0;background:#fff;border-top:1px solid #eee}
    .site-footer .footer-inner{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .site-footer .social{display:flex;flex-wrap:wrap;gap:12px;}

    /* Common styles for all social links */
    .site-footer .social a {
        display:inline-flex;align-items:center;gap:8px;
        padding:8px 12px;border-radius:999px;
        font-weight:700;text-decoration:none;
        transition:transform .12s, box-shadow .12s;
    }
    .site-footer .social a:hover{transform:translateY(-1px);}
    .site-footer .social a svg{width:20px;height:20px;fill:currentColor}

    /* YouTube */
    .site-footer .social .yt{
        background:#ffefef;border:1px solid #ffd6d6;color:#c51616;
        box-shadow:0 6px 14px rgba(197,22,22,.16);
    }
    .site-footer .social .yt:hover{box-shadow:0 10px 20px rgba(197,22,22,.22)}

    /* Instagram */
    .site-footer .social .ig{
        background:#f8f0ff;border:1px solid #e9d6ff;color:#8a3ab9;
        box-shadow:0 6px 14px rgba(138,58,185,.16);
    }
    .site-footer .social .ig:hover{box-shadow:0 10px 20px rgba(138,58,185,.22)}

    /* Email */
    .site-footer .social .email{
        background:#eff6ff;border:1px solid #dbeafe;color:#2563eb;
        box-shadow:0 6px 14px rgba(37,99,235,.16);
    }
    .site-footer .social .email:hover{box-shadow:0 10px 20px rgba(37,99,235,.22)}

    @media (max-width:640px){ .site-footer .footer-inner{flex-direction:column;align-items:flex-start} }
</style>

</body>
</html>
