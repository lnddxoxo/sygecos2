// Hero Slider functionality
let currentSlide = 0;
const slides = document.querySelectorAll('.slide');
const thumbnails = document.querySelectorAll('.pagination-thumb');

function showSlide(index) {
    slides.forEach((slide, i) => {
        slide.classList.toggle('active', i === index);
    });
    thumbnails.forEach((thumb, i) => {
        thumb.classList.toggle('active', i === index);
    });
}

function nextSlide() {
    currentSlide = (currentSlide + 1) % slides.length;
    showSlide(currentSlide);
}

// Auto-advance hero slider
setInterval(nextSlide, 5000);

// Hero thumbnail click handlers
thumbnails.forEach((thumb, index) => {
    thumb.addEventListener('click', () => {
        currentSlide = index;
        showSlide(currentSlide);
    });
});

// Navigation scroll effect
window.addEventListener('scroll', function() {
    const navbar = document.querySelector('.navbar');
    if (window.scrollY > 50) {
        navbar.classList.add('scrolled');
    } else {
        navbar.classList.remove('scrolled');
    }
});

// Smooth scrolling for navigation links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});

// Scroll reveal animation
const scrollRevealElements = document.querySelectorAll('.scroll-reveal');
const revealElementOnScroll = () => {
    scrollRevealElements.forEach(element => {
        const elementTop = element.getBoundingClientRect().top;
        const elementVisible = 150; // Pixels from bottom of viewport to trigger reveal
        
        if (elementTop < window.innerHeight - elementVisible) {
            element.classList.add('revealed');
        } else {
            // Optional: remove 'revealed' class if element scrolls back up
            // element.classList.remove('revealed');
        }
    });
};

window.addEventListener('scroll', revealElementOnScroll);
revealElementOnScroll(); // Initial check on page load to reveal elements already in view

// Add interactive hover effects
document.querySelectorAll('.process-card, .university-feature-img').forEach(card => {
    card.addEventListener('mouseenter', function() {
        if (this.classList.contains('process-card')) {
            this.style.transform = 'translateY(-8px)';
            this.style.boxShadow = 'var(--shadow-xl)';
        } else if (this.classList.contains('university-feature-img')) {
            this.style.transform = 'scale(1.05)';
        }
    });
    
    card.addEventListener('mouseleave', function() {
        if (this.classList.contains('process-card')) {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = 'var(--shadow-sm)';
        } else if (this.classList.contains('university-feature-img')) {
            this.style.transform = 'scale(1)';
        }
    });
});