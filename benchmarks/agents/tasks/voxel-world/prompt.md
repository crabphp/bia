Build a small voxel world game, in the style of Minecraft, as one file: `index.html` in the current directory.

Hard rules:
- One self-contained `index.html`: plain WebGL2 and JavaScript, no libraries, no CDN, no network requests, no build step. It must work opened straight from disk (file://).
- No console errors.

The world:
- Procedurally generated terrain from seeded noise you implement yourself: at least 192 × 192 blocks across and 64 high. Read the seed from `?seed=N` in the URL, with a fixed default, so the same seed always gives the same world.
- Biomes or at least varied terrain: hills and valleys, sand near water, grass on top, dirt under it, stone deeper down, and snow on the high peaks.
- Water at a sea level, drawn translucent.
- Trees (trunk and leaves) scattered on grass.
- Each block type has its own look, generated in code (a procedural texture atlas, or per-face shading with noise). No image files.

Rendering:
- Split the world into chunks, and build each chunk's mesh with faces between two solid blocks removed. Rebuild only the chunks a change touches.
- Directional sunlight with simple per-face shading, plus ambient occlusion at the block corners.
- A day/night cycle of about 2 minutes: the sun moves, the sky colour and light change, and stars appear at night.
- Distance fog blending into the sky colour.
- Steady frame rate: do not rebuild meshes every frame.

Playing:
- First-person camera: click to lock the pointer, mouse to look, WASD to move, Space to jump, with gravity and collision against blocks, so you cannot walk through terrain. F toggles a free-flying mode with no collision.
- Left click removes the block under the crosshair, right click places the selected block against the face you are looking at, using a proper voxel raycast up to 6 blocks away. Keys 1–5 choose the block to place (grass, dirt, stone, wood, glass), shown in a small hotbar.
- Outline the block under the crosshair.
- A HUD with a crosshair, frames per second, the player's position, and the time of day.

When you are done, tell me in a few lines what you built and anything you left out.
