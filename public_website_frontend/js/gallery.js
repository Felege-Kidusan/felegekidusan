/**
 * Felege Birhan Sunday School - Interactive Gallery Controller
 * Handles Category Filtering, Responsive Grid, and Lightbox Preview
 */

const GalleryController = {
  items: [],
  currentFilter: 'all',
  activeImageIndex: 0,

  async init() {
    await this.fetchGalleryItems();
    this.bindFilterEvents();
    this.bindLightboxEvents();
    this.render();
  },

  async fetchGalleryItems() {
    try {
      const response = await fetch('data/gallery.json');
      if (response.ok) {
        this.items = await response.json();
      }
    } catch (e) {
      console.warn('Using default gallery items:', e);
    }
  },

  bindFilterEvents() {
    const filterButtons = document.querySelectorAll('.filter-btn');
    filterButtons.forEach(btn => {
      btn.addEventListener('click', (e) => {
        filterButtons.forEach(b => b.classList.remove('active'));
        e.target.classList.add('active');
        this.currentFilter = e.target.getAttribute('data-filter') || 'all';
        this.render();
      });
    });
  },

  bindLightboxEvents() {
    const modal = document.getElementById('lightbox-modal');
    const closeBtn = document.getElementById('lightbox-close-btn');

    if (!modal) return;

    if (closeBtn) {
      closeBtn.addEventListener('click', () => this.closeLightbox());
    }

    modal.addEventListener('click', (e) => {
      if (e.target === modal) {
        this.closeLightbox();
      }
    });

    document.addEventListener('keydown', (e) => {
      if (!modal.classList.contains('active')) return;
      if (e.key === 'Escape') this.closeLightbox();
      if (e.key === 'ArrowRight') this.navigateLightbox(1);
      if (e.key === 'ArrowLeft') this.navigateLightbox(-1);
    });
  },

  getFilteredItems() {
    if (this.currentFilter === 'all') return this.items;
    return this.items.filter(item => item.category === this.currentFilter);
  },

  render() {
    const container = document.getElementById('gallery-grid-container');
    if (!container) return;

    const filtered = this.getFilteredItems();
    const isAm = (localStorage.getItem('fkss_lang') || 'en') === 'am';

    if (filtered.length === 0) {
      container.innerHTML = `
        <div style="grid-column: 1/-1; text-align: center; padding: 3rem; color: var(--text-muted);">
          <i class="fa-regular fa-image" style="font-size: 3rem; margin-bottom: 1rem; color: var(--accent-gold);"></i>
          <p>No photos found in this category.</p>
        </div>
      `;
      return;
    }

    container.innerHTML = filtered.map((item, index) => `
      <div class="gallery-item" data-index="${index}" onclick="GalleryController.openLightbox(${index})">
        <img src="${item.image_url}" alt="${item.title}" class="gallery-thumbnail" loading="lazy">
        <div class="gallery-overlay">
          <span class="gallery-item-category">${item.category_label || item.category}</span>
          <h4 class="gallery-item-title">${isAm ? item.title_am : item.title}</h4>
        </div>
      </div>
    `).join('');
  },

  openLightbox(index) {
    const filtered = this.getFilteredItems();
    if (!filtered[index]) return;

    this.activeImageIndex = index;
    const item = filtered[index];
    const isAm = (localStorage.getItem('fkss_lang') || 'en') === 'am';

    const modal = document.getElementById('lightbox-modal');
    const imgEl = document.getElementById('lightbox-img');
    const titleEl = document.getElementById('lightbox-title');
    const captionEl = document.getElementById('lightbox-caption');

    if (modal && imgEl) {
      imgEl.src = item.image_url;
      imgEl.alt = item.title;
      if (titleEl) titleEl.innerText = isAm ? item.title_am : item.title;
      if (captionEl) captionEl.innerText = isAm ? item.caption_am : item.caption;
      modal.classList.add('active');
      document.body.style.overflow = 'hidden';
    }
  },

  navigateLightbox(direction) {
    const filtered = this.getFilteredItems();
    let nextIndex = this.activeImageIndex + direction;
    if (nextIndex < 0) nextIndex = filtered.length - 1;
    if (nextIndex >= filtered.length) nextIndex = 0;
    this.openLightbox(nextIndex);
  },

  closeLightbox() {
    const modal = document.getElementById('lightbox-modal');
    if (modal) {
      modal.classList.remove('active');
      document.body.style.overflow = '';
    }
  }
};

document.addEventListener('DOMContentLoaded', () => {
  GalleryController.init();
});
