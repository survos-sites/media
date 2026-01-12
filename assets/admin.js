/**
 * EasyAdmin entrypoint (separate from app.js) to avoid bootstrap/tabler collisions
 * and ensure Stimulus + Symfony UX components load as expected.
 */
import './stimulus_bootstrap.js';

// Optional: JSON viewer (requires importmap/npm dependency)
// import "@andypf/json-viewer";