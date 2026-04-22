/**
 * Face Liveness Detector Module
 * Standalone module for face detection and blink verification
 * Can be integrated into any attendance system
 */

class FaceLivenessDetector {
  constructor(config = {}) {
    this.config = {
      FACE_DETECTION_THRESHOLD: 0.5,
      EYE_CLOSURE_THRESHOLD: 0.3,
      REQUIRED_BLINKS: 2,
      BLINK_COOLDOWN_MS: 300,
      DETECTION_INTERVAL_MS: 100,
      ...config
    };
    
    this.state = {
      stream: null,
      isRunning: false,
      modelsLoaded: false,
      blinkCount: 0,
      livenessVerified: false,
      currentFace: null,
      eyesOpen: false,
      faceConfidence: 0,
      faceCount: 0,
    };
    
    this.callbacks = {
      onFaceDetected: () => {},
      onBlinkDetected: () => {},
      onLivenessVerified: () => {},
      onStatusChange: () => {},
      onError: () => {},
    };
  }
  
  /**
   * Initialize detector with video element and canvas
   */
  async init(videoElement, canvasElement) {
    this.videoEl = videoElement;
    this.canvasEl = canvasElement;
    
    try {
      // Load face-api models
      await Promise.all([
        faceapi.nets.tinyFaceDetector.loadFromUri('/models'),
        faceapi.nets.faceLandmark68Net.loadFromUri('/models'),
        faceapi.nets.faceExpressionNet.loadFromUri('/models'),
      ]);
      
      this.state.modelsLoaded = true;
      this.emit('statusChange', { status: 'ready', message: 'Detector ready' });
    } catch (err) {
      this.emit('error', { message: `Failed to load models: ${err.message}` });
      throw err;
    }
  }
  
  /**
   * Start camera and detection
   */
  async start() {
    if (!this.state.modelsLoaded) {
      throw new Error('Detector not initialized. Call init() first.');
    }
    
    try {
      this.state.stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user' },
        audio: false,
      });
      
      this.videoEl.srcObject = this.state.stream;
      this.state.isRunning = true;
      
      // Start detection loop
      this.startDetectionLoop();
      this.emit('statusChange', { status: 'running', message: 'Camera started' });
    } catch (err) {
      this.emit('error', { message: `Camera access failed: ${err.message}` });
      throw err;
    }
  }
  
  /**
   * Stop camera and detection
   */
  stop() {
    if (this.state.stream) {
      this.state.stream.getTracks().forEach(track => track.stop());
      this.state.stream = null;
    }
    
    this.state.isRunning = false;
    this.videoEl.srcObject = null;
    this.emit('statusChange', { status: 'stopped', message: 'Camera stopped' });
  }
  
  /**
   * Reset detection state
   */
  reset() {
    this.state.blinkCount = 0;
    this.state.livenessVerified = false;
    this.state.currentFace = null;
    this.state.eyesOpen = false;
  }
  
  /**
   * Get current state
   */
  getState() {
    return { ...this.state };
  }
  
  /**
   * Register callback
   */
  on(event, callback) {
    if (this.callbacks[`on${this.capitalize(event)}`]) {
      this.callbacks[`on${this.capitalize(event)}`] = callback;
    }
  }
  
  /**
   * Emit event
   */
  emit(event, data) {
    const callback = this.callbacks[`on${this.capitalize(event)}`];
    if (callback) callback(data);
  }
  
  /**
   * Capitalize string
   */
  capitalize(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
  }
  
  /**
   * Internal: Start detection loop
   */
  startDetectionLoop() {
    setInterval(async () => {
      if (!this.state.isRunning) return;
      
      try {
        // Detect faces
        this.canvasEl.width = this.videoEl.videoWidth;
        this.canvasEl.height = this.videoEl.videoHeight;
        
        const ctx = this.canvasEl.getContext('2d');
        ctx.drawImage(this.videoEl, 0, 0);
        
        const detections = await faceapi
          .detectAllFaces(this.canvasEl, new faceapi.TinyFaceDetectorOptions())
          .withFaceLandmarks();
        
        this.state.faceCount = detections.length;
        
        if (detections.length === 1) {
          this.state.currentFace = detections[0];
          this.state.faceConfidence = (detections[0].detection.score * 100).toFixed(1);
          
          // Detect blink
          const landmarks = detections[0].landmarks.positions;
          const eyeClosure = this.calculateEyeClosure(landmarks);
          this.detectBlink(eyeClosure);
          
          this.emit('faceDetected', { confidence: this.state.faceConfidence });
        } else {
          this.state.currentFace = null;
        }
      } catch (err) {
        console.error('Detection error:', err);
      }
    }, this.config.DETECTION_INTERVAL_MS);
  }
  
  /**
   * Calculate eye closure ratio
   */
  calculateEyeClosure(landmarks) {
    if (!landmarks || landmarks.length < 68) return 1;
    
    const leftEye = landmarks.slice(36, 42);
    const rightEye = landmarks.slice(42, 48);
    
    const calculateEAR = (eye) => {
      if (eye.length < 6) return 1;
      const dist = (p1, p2) => Math.sqrt(Math.pow(p1.x - p2.x, 2) + Math.pow(p1.y - p2.y, 2));
      const numerator = dist(eye[1], eye[5]) + dist(eye[2], eye[4]);
      const denominator = 2 * dist(eye[0], eye[3]);
      return denominator === 0 ? 1 : numerator / denominator;
    };
    
    const leftEAR = calculateEAR(leftEye);
    const rightEAR = calculateEAR(rightEye);
    
    return (leftEAR + rightEAR) / 2 > 0.3 ? 1 : 0;
  }
  
  /**
   * Detect blink
   */
  detectBlink(eyeClosure) {
    const now = Date.now();
    const timeSinceLastBlink = now - (this.lastBlinkTime || 0);
    
    const isBlinkEnding = (this.lastEyeClosure || 1) <= this.config.EYE_CLOSURE_THRESHOLD && eyeClosure > 0.5;
    
    if (isBlinkEnding && timeSinceLastBlink > this.config.BLINK_COOLDOWN_MS) {
      this.state.blinkCount++;
      this.lastBlinkTime = now;
      
      this.emit('blinkDetected', { blinkCount: this.state.blinkCount });
      
      if (this.state.blinkCount >= this.config.REQUIRED_BLINKS) {
        this.state.livenessVerified = true;
        this.emit('livenessVerified', { blinkCount: this.state.blinkCount });
      }
    }
    
    this.lastEyeClosure = eyeClosure;
    this.state.eyesOpen = eyeClosure > 0.5;
  }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
  module.exports = FaceLivenessDetector;
}