// Offline tests of the Worker's device selection, model source validation and message protocol.
import test from 'node:test';
import assert from 'node:assert/strict';
import { probe, candidates, modelSource, handle } from '../../assets/qwen-worker.js';

const adapter = (features = [], fallback = false) => ({
  features: new Set(features),
  info: { vendor: 'test', architecture: 'x', isFallbackAdapter: fallback },
  limits: { maxBufferSize: 2 ** 31 },
});

test('probe reports missing WebGPU, missing adapter and f16 support', async () => {
  assert.equal((await probe({ hardwareConcurrency: 8 })).webgpu, false);
  const none = await probe({ gpu: { requestAdapter: async () => null } });
  assert.equal(none.webgpu, true);
  assert.equal(none.adapter, null);
  const f16 = await probe({ gpu: { requestAdapter: async () => adapter(['shader-f16']) } });
  assert.equal(f16.f16, true);
  const broken = await probe({ gpu: { requestAdapter: async () => { throw new Error('denied'); } } });
  assert.equal(broken.adapter, null);
  assert.match(broken.adapterError, /denied/);
});

test('candidates prefer real GPUs, skip software adapters and fall back to WASM', async () => {
  const gpuF16 = await probe({ gpu: { requestAdapter: async () => adapter(['shader-f16']) } });
  assert.deepEqual(candidates(gpuF16), [{ device: 'webgpu', dtype: 'q4f16' }, { device: 'wasm', dtype: 'q4' }]);
  const gpu = await probe({ gpu: { requestAdapter: async () => adapter() } });
  assert.deepEqual(candidates(gpu)[0], { device: 'webgpu', dtype: 'q4' });
  const software = await probe({ gpu: { requestAdapter: async () => adapter([], true) } });
  assert.deepEqual(candidates(software), [{ device: 'wasm', dtype: 'q4' }]);
  assert.deepEqual(candidates(software, 'webgpu'), [{ device: 'webgpu', dtype: 'q4' }]);
  const nogpu = await probe({});
  assert.deepEqual(candidates(nogpu, 'webgpu'), []);
  assert.deepEqual(candidates(gpuF16, 'wasm'), [{ device: 'wasm', dtype: 'q4' }]);
});

test('modelSource only allows the pinned model, valid revisions and same-origin self hosting', () => {
  const ok = { model: 'onnx-community/Qwen3-0.6B-ONNX', revision: '58ca03a5e7b354b480b1a82b34bde8dc7b854cc0' };
  assert.deepEqual(modelSource(ok), { remoteHost: 'https://huggingface.co/', remotePathTemplate: '{model}/resolve/{revision}/' });
  assert.throws(() => modelSource({ ...ok, model: 'Qwen/Qwen3-Embedding-0.6B' }), /danh sách cho phép/);
  assert.throws(() => modelSource({ ...ok, revision: '../../x' }), /revision/);
  assert.throws(() => modelSource({ ...ok, selfHostedBase: 'https://evil.example/models/' }), /cùng tên miền/);
  const here = new URL('../../assets/', import.meta.url).href; // file:// origin in Node
  assert.equal(modelSource({ ...ok, selfHostedBase: here }).remotePathTemplate, '{model}/');
});

test('message protocol rejects unknown actions and generation before load', async () => {
  const sent = [];
  const onmessage = handle(m => sent.push(m));
  await onmessage({ data: { id: 1, action: 'rm -rf' } });
  await onmessage({ data: { id: 2, action: 'generate', payload: { messages: [] } } });
  await onmessage({ data: { action: 'interrupt' } });
  assert.deepEqual(sent.map(m => [m.id, m.ok, m.code]), [[1, false, 'BAD_REQUEST'], [2, false, 'NOT_LOADED']]);
  await onmessage({ data: { id: 3, action: 'load', payload: { config: { model: 'evil/model', revision: 'main' } } } });
  assert.equal(sent.at(-1).code, 'BAD_REQUEST');
});
