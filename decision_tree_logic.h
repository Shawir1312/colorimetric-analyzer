// File otomatis hasil pelatihan Decision Tree
#ifndef DECISION_TREE_LOGIC_H
#define DECISION_TREE_LOGIC_H

// Pohon Keputusan untuk Pb
void eval_tree_Pb(float F1_415nm, float F2_445nm, float F3_480nm, float F4_515nm,
                    float F5_555nm, float F6_590nm, float F7_630nm, float F8_680nm,
                    float Lab_L, float Lab_a, float Lab_b, float Delta_E, float Key_Ratio,
                    float &outConc, bool &outSafe) {
  if (Lab_a <= -2.5750f) {
    if (F1_415nm <= 0.3725f) {
      outConc = 50.0000f; outSafe = false;
    } else {
      outConc = 100.0000f; outSafe = false;
    }
  } else {
    if (Lab_a <= -1.9400f) {
      outConc = 15.0000f; outSafe = false;
    } else {
      if (Delta_E <= 46.2000f) {
        outConc = 4.0000f; outSafe = false;
      } else {
        outConc = 0.2514f; outSafe = false;
      }
    }
  }
}

// Pohon Keputusan untuk Hg
void eval_tree_Hg(float F1_415nm, float F2_445nm, float F3_480nm, float F4_515nm,
                    float F5_555nm, float F6_590nm, float F7_630nm, float F8_680nm,
                    float Lab_L, float Lab_a, float Lab_b, float Delta_E, float Key_Ratio,
                    float &outConc, bool &outSafe) {
  if (Lab_a <= -0.7950f) {
    if (F1_415nm <= 0.4730f) {
      if (F1_415nm <= 0.3630f) {
        outConc = 0.2550f; outSafe = false;
      } else {
        outConc = 1.0000f; outSafe = false;
      }
    } else {
      outConc = 0.0750f; outSafe = false;
    }
  } else {
    if (Lab_a <= -0.7050f) {
      outConc = 70.0000f; outSafe = false;
    } else {
      if (Lab_a <= 0.3150f) {
        outConc = 6.3500f; outSafe = false;
      } else {
        outConc = 60.0000f; outSafe = false;
      }
    }
  }
}

// Pohon Keputusan untuk Cu
void eval_tree_Cu(float F1_415nm, float F2_445nm, float F3_480nm, float F4_515nm,
                    float F5_555nm, float F6_590nm, float F7_630nm, float F8_680nm,
                    float Lab_L, float Lab_a, float Lab_b, float Delta_E, float Key_Ratio,
                    float &outConc, bool &outSafe) {
  if (F1_415nm <= 0.5725f) {
    if (F1_415nm <= 0.5585f) {
      if (F1_415nm <= 0.5555f) {
        outConc = 0.0367f; outSafe = true;
      } else {
        outConc = 0.0500f; outSafe = true;
      }
    } else {
      if (Lab_a <= -0.5400f) {
        outConc = 1.0000f; outSafe = true;
      } else {
        outConc = 6.4333f; outSafe = false;
      }
    }
  } else {
    if (F1_415nm <= 0.5755f) {
      outConc = 50.0000f; outSafe = false;
    } else {
      outConc = 100.0000f; outSafe = false;
    }
  }
}

// Pohon Keputusan untuk Fe
void eval_tree_Fe(float F1_415nm, float F2_445nm, float F3_480nm, float F4_515nm,
                    float F5_555nm, float F6_590nm, float F7_630nm, float F8_680nm,
                    float Lab_L, float Lab_a, float Lab_b, float Delta_E, float Key_Ratio,
                    float &outConc, bool &outSafe) {
  if (Delta_E <= 16.9050f) {
    outConc = 80.0000f; outSafe = false;
  } else {
    if (F8_680nm <= 0.6070f) {
      if (F6_590nm <= 0.8960f) {
        outConc = 0.1420f; outSafe = true;
      } else {
        outConc = 7.8000f; outSafe = false;
      }
    } else {
      outConc = 20.0250f; outSafe = true;
    }
  }
}

#endif
