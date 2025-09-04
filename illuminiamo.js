document.addEventListener('DOMContentLoaded', function() {
    const hamburger = document.querySelector('.icon-hamburger');
    const menu = document.querySelector('.header__menu');
    
    hamburger.addEventListener('click', function() {
      menu.classList.toggle('active');
    });
  });
  