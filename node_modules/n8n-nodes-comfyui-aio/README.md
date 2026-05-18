# n8n-nodes-comfyui-aio

> Execute ComfyUI workflows in n8n - Generate images, videos, audios and more with AI! (All-In-One)

[![npm version](https://badge.fury.io/js/n8n-nodes-comfyui-aio.svg)](https://www.npmjs.org/package/n8n-nodes-comfyui-aio)

## Video Tutorials

| Platform | Link | Description |
|----------|------|-------------|
| 📺 YouTube | [Watch Tutorial](https://youtu.be/wsbo3hBKsPM) | English tutorial |
| 📺 Bilibili | [观看教程](https://www.bilibili.com/video/BV1ffrFBTEdQ/?vd_source=6485fe2fae664d8b09cb2e2fd7df5ef7) | 中文教程 |

## What This Does

This package adds **one intelligent node** to n8n that automatically detects how it's being used:

**ComfyUI Node** - Universal ComfyUI workflow executor (All-In-One)
- ✅ **Auto-detects execution mode** (Tool for AI Agents or Action for workflows)
- ✅ **Manual mode override** available
- ✅ **Works with AI Agents** as a tool
- ✅ **Works in regular workflows** with full binary support
- ✅ **Supports image, video and audio input** (URL and binary)
- ✅ **Generate images, videos, and audios**
- ✅ **Dynamic parameter overrides**

You can use this node to:
- Generate images from text prompts
- Process and edit images
- Generate videos
- Generate and process audio
- Use AI Agents to create images/videos/audios automatically
- And much more with any ComfyUI workflow!

---

## Quick Setup

### 1. Install
```bash
# n8n Cloud: Settings → Community Nodes → Install → n8n-nodes-comfyui-aio
# Self-hosted:
cd ~/.n8n
npm install n8n-nodes-comfyui-aio
```

### 2. Run ComfyUI

**Option A: Local ComfyUI**
```bash
# Default: http://127.0.0.1:8188
```

**Option B: RunningHub Cloud** (Recommended for AI Agents)
- Sign up: [https://www.runninghub.cn/](https://www.runninghub.cn/)
- Get ComfyUI URL and API key
- Copy the API format workflow URL

### 3. Configure n8n Workflow

1. Add a **ComfyUI** node
2. Set **ComfyUI URL** (e.g., `http://127.0.0.1:8188`)
3. Paste your **ComfyUI workflow JSON** (API format)
4. Run!

---

## Features

### Execution Modes

The node automatically detects how it's being executed:

| Mode | When Used | Returns |
|------|-----------|---------|
| **Tool Mode** | Called by AI Agents | URLs only (no binary data) |
| **Action Mode** | Regular workflow | Full binary data + URLs |

You can also manually override the mode in node settings.

### Dynamic Parameters

Override any workflow node parameter dynamically:

**Parameters:**
- ✅ **Text** - Prompt text, seeds, etc.
- ✅ **Number** - Steps, dimensions, batch size
- ✅ **Boolean** - Toggle options
- ✅ **File** - Images, videos, or audios (URL or binary)

**Example:**
```json
{
  "nodeId": "6",
  "parameterMode": "single",
  "paramName": "width",
  "type": "number",
  "numberValue": 1024
}
```

### File Input

Supports multiple input methods:

**From URL:**
- Downloads from HTTP/HTTPS URLs
- Validates file type (image/video/audio)
- Uploads to ComfyUI automatically

**From Binary:**
- Uses binary data from previous nodes
- Auto-detects file type
- Validates MIME type and size

### Supported Formats

**Images:** PNG, JPG, JPEG, WEBP, GIF, BMP, SVG
**Videos:** MP4, WEBM, AVI, MOV, MKV, GIF
**Audios:** MP3, WAV, OGG, FLAC, AAC, M4A, WMA, OPUS

### Size Limits

- Images: Up to 50MB
- Videos: Up to 500MB
- Audios: Up to 100MB

---

## Getting ComfyUI Workflow JSON

ComfyUI workflows are exported in API format:

1. Open ComfyUI (`http://127.0.0.1:8188`)
2. Design your workflow
3. Click **Save (API Format)** button
4. Copy the generated JSON
5. Paste it in the n8n ComfyUI node

---

## Examples

### Image Generation

```json
{
  "3": {
    "inputs": {
      "seed": 123456789,
      "steps": 20,
      "cfg": 8,
      "sampler_name": "euler",
      "scheduler": "normal",
      "denoise": 1
    },
    "class_type": "KSampler"
  },
  "6": {
    "inputs": {
      "text": "a beautiful sunset over the ocean",
      "clip": ["4", 1]
    },
    "class_type": "CLIPTextEncode"
  }
}
```

### Video Generation

Use the same format with video-specific nodes in ComfyUI.

### Audio Generation

Use the same format with audio-specific nodes in ComfyUI.

---

## Development

```bash
# Build
npm run build

# Lint
npm run lint

# Test
npm test

# Watch mode
npm run dev
```

---

## Contributing

Contributions are welcome! Please read the contributing guidelines.

---

## License

MIT License - see [LICENSE](LICENSE) for details.

---

## Links

- Repository: https://github.com/bitspring/n8n-nodes-comfyui-all
- n8n Community: https://community.n8n.io
- ComfyUI: https://github.com/comfyanonymous/ComfyUI

---

## Changelog

### v2.5.2

- ✨ Added audio support (input/output)
- 🐛 Fixed TypeScript compilation issues
- 📝 Updated documentation

### v2.5.1

- ✨ Video and GIF support
- ✨ Auto-detection for execution mode
- 🐛 Bug fixes

### v2.5.0

- ✨ AI Agent tool mode support
- ✨ Dynamic parameter overrides
- 🐛 Various improvements

---

## Related Projects

- [ComfyUI](https://github.com/comfyanonymous/ComfyUI) - Powerful UI for Stable Diffusion
- [n8n](https://n8n.io) - Workflow automation tool
- [RunningHub](https://www.runninghub.cn/) - ComfyUI cloud service

---

## Support

- GitHub Issues: https://github.com/bitspring/n8n-nodes-comfyui-all/issues
- n8n Community Forum: https://community.n8n.io

---

## Star History

[![Star History Chart](https://api.star-history.com/svg?repos=bitspring/n8n-nodes-comfyui-all&type=Timeline)](https://star-history.com/#bitspring/n8n-nodes-comfyui-all&Timeline)
