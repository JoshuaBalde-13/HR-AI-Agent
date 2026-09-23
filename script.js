document.addEventListener("DOMContentLoaded", () => {
    const header = document.getElementById("header");
    const menuToggle = document.getElementById("menuToggle");
    const nav = document.getElementById("nav");
    const navLinks = document.querySelectorAll(".nav-link");
    const sections = document.querySelectorAll("section[id]");
    const modal = document.getElementById("applicationModal");
    const modalClose = document.getElementById("modalClose");
    const modalOverlay = document.querySelector(".modal-overlay");
    const applyButtons = document.querySelectorAll(".apply-btn");
    const applicationForm = document.getElementById("applicationForm");
    const positionInput = document.getElementById("position");
    const birthdateInput = document.getElementById("birthdate");
    const passwordInput = document.getElementById("password");
    const confirmPasswordInput = document.getElementById("confirmPassword");
    const resumeInput = document.getElementById("resume");
    const applicationError = document.getElementById("applicationError");
    const applicationSuccess = document.getElementById("applicationSuccess");
    const contactForm = document.getElementById("contactForm");
    const contactSuccess = document.getElementById("contactSuccess");

    window.addEventListener("scroll", () => {
        if (window.scrollY > 50) {
            header.classList.add("scrolled");
        } else {
            header.classList.remove("scrolled");
        }
    });

    menuToggle.addEventListener("click", () => {
        nav.classList.toggle("active");
    });

    navLinks.forEach(link => {
        link.addEventListener("click", () => {
            nav.classList.remove("active");
        });
    });

    const sectionObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const id = entry.target.getAttribute("id");
                navLinks.forEach(link => {
                    link.classList.toggle("active", link.getAttribute("href") === `#${id}`);
                });
            }
        });
    }, {
        threshold: 0.25,
        rootMargin: "-80px 0px -40% 0px"
    });

    sections.forEach(section => {
        sectionObserver.observe(section);
    });

    function openModal(position) {
        positionInput.value = position;
        applicationError.style.display = "none";
        applicationSuccess.style.display = "none";
        applicationForm.reset();
        positionInput.value = position;
        modal.classList.add("active");
        document.body.classList.add("modal-open");
    }

    function closeModal() {
        modal.classList.remove("active");
        document.body.classList.remove("modal-open");
    }

    applyButtons.forEach(button => {
        button.addEventListener("click", () => {
            openModal(button.dataset.position);
        });
    });

    modalClose.addEventListener("click", closeModal);
    modalOverlay.addEventListener("click", closeModal);

    document.addEventListener("keydown", event => {
        if (event.key === "Escape" && modal.classList.contains("active")) {
            closeModal();
        }
    });

    applicationForm.addEventListener("submit", event => {
        event.preventDefault();
        applicationError.style.display = "none";
        applicationSuccess.style.display = "none";

        if (birthdateInput && !birthdateInput.value) {
            applicationError.textContent = "Please select your birthdate.";
            applicationError.style.display = "block";
            return;
        }

        if (passwordInput.value !== confirmPasswordInput.value) {
            applicationError.textContent = "Passwords do not match.";
            applicationError.style.display = "block";
            return;
        }

        const file = resumeInput.files[0];

        if (!file) {
            applicationError.textContent = "Please upload your PDF resume.";
            applicationError.style.display = "block";
            return;
        }

        if (file.type !== "application/pdf" && !file.name.toLowerCase().endsWith(".pdf")) {
            applicationError.textContent = "Only PDF resume files are accepted.";
            applicationError.style.display = "block";
            return;
        }

        applicationSuccess.style.display = "block";
        applicationForm.reset();
        positionInput.value = "";

        setTimeout(() => {
            closeModal();
            applicationSuccess.style.display = "none";
        }, 2500);
    });

    if (contactForm) {
        contactForm.addEventListener("submit", event => {
            event.preventDefault();
            contactSuccess.style.display = "block";
            contactForm.reset();

            setTimeout(() => {
                contactSuccess.style.display = "none";
            }, 3000);
        });
    }
});