</main>
<style>
    .footer {
        background: #0c2d48;
        padding: 25px 0;
        text-align: center;
        margin-top: auto;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        box-shadow: 0 -4px 6px rgba(0, 0, 0, 0.1);
        position: relative;
        overflow: hidden;
    }

    .footer::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(to right, #039be5, #4CAF50, #039be5);
        animation: shimmer 2s infinite linear;
    }

    @keyframes shimmer {
        0% { transform: translateX(-100%); }
        100% { transform: translateX(100%); }
    }

    .footer-content {
        max-width: 1200px;
        margin: 0 auto;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 30px;
        flex-wrap: wrap;
        padding: 0 20px;
    }

    .footer-logo {
        position: relative;
        display: flex;
        align-items: center;
        gap: 15px;
        transition: transform 0.3s ease;
    }

    .footer-logo:hover {
        transform: scale(1.05);
    }

    .footer-text {
        color: #ffffff;
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 15px;
        transition: transform 0.3s ease;
    }

    .footer-copyright {
        font-size: 14px;
        color: rgba(255, 255, 255, 0.7);
        margin-top: 15px;
    }

    .footer-brand {
        color: #039be5;
        font-style: italic;
        font-weight: 500;
        transition: color 0.3s ease;
    }

    .footer-brand:hover {
        color: #4CAF50;
    }

    .footer-social {
        display: flex;
        gap: 15px;
        justify-content: center;
        margin-top: 20px;
    }

    .social-icon {
        color: #ffffff;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.1);
        transition: all 0.3s ease;
    }

    .social-icon:hover {
        background: #039be5;
        transform: translateY(-3px);
    }

    @media (max-width: 768px) {
        .footer-content {
            flex-direction: column;
            gap: 20px;
        }
    }
</style>

<footer class="footer">
    <div class="footer-content">
        <div class="footer-text">
            <div class="footer-logo">UV</div>
            <span class="footer-brand">Université Virtuelle</span>
        </div>
    </div>
    <div class="footer-social">
        <a href="https://www.facebook.com/profile.php?id=61557682561488&mibextid=ZbWKwL" 
           class="social-icon" title="Facebook" target="_blank" rel="noopener noreferrer">
            <i class="fab fa-facebook-f"></i>
        </a>
        <a href="https://x.com/codingenterpris" 
           class="social-icon" title="X" target="_blank" rel="noopener noreferrer">
            <i class="fab fa-twitter"></i>
        </a>
        <a href="https://www.linkedin.com/company/coding-enterprise" 
           class="social-icon" title="LinkedIn" target="_blank" rel="noopener noreferrer">
            <i class="fab fa-linkedin-in"></i>
        </a>
        <a href="https://www.instagram.com/coding_enterprise1?igsh=MXhhdm56dXdhdmlqaA==" 
           class="social-icon" title="Instagram" target="_blank" rel="noopener noreferrer">
            <i class="fab fa-instagram"></i>
        </a>
    </div>
    <div class="footer-copyright">
        <p>&copy; <?php echo date('Y'); ?> Université Virtuelle | 
           <span class="footer-brand">from Coding Enterprise</span></p>
    </div>
</footer>

</body>
</html>