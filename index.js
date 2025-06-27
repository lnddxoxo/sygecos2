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

// University Slider functionality
let currentUniversitySlide = 0;
const universitySlides = document.querySelectorAll('.university-slide');
const universityThumbnails = document.querySelectorAll('.university-thumb');

function showUniversitySlide(index) {
    universitySlides.forEach((slide, i) => {
        slide.classList.toggle('active', i === index);
    });
    universityThumbnails.forEach((thumb, i) => {
        thumb.classList.toggle('active', i === index);
    });
}

function nextUniversitySlide() {
    currentUniversitySlide = (currentUniversitySlide + 1) % universitySlides.length;
    showUniversitySlide(currentUniversitySlide);
}

// Auto-advance university slider
setInterval(nextUniversitySlide, 4000);

// University thumbnail click handlers
universityThumbnails.forEach((thumb, index) => {
    thumb.addEventListener('click', () => {
        currentUniversitySlide = index;
        showUniversitySlide(currentUniversitySlide);
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
        const elementVisible = 150;
        
        if (elementTop < window.innerHeight - elementVisible) {
            element.classList.add('revealed');
        }
    });
};

window.addEventListener('scroll', revealElementOnScroll);
revealElementOnScroll(); // Initial check

// Add interactive hover effects
document.querySelectorAll('.process-card').forEach(card => {
    card.addEventListener('mouseenter', function() {
        this.style.transform = 'translateY(-8px) scale(1.02)';
    });
    
    card.addEventListener('mouseleave', function() {
        this.style.transform = 'translateY(0) scale(1)';
    });
});