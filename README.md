# 🧊 FoodPrep Recipes / Freezer Inventory

**FoodPrep Recipes / Freezer Inventory** is a lightweight, self-hosted web application for managing meal prep, freezer inventory, and cooking workflows in a **realistic, real-world way**.

The focus is **not** on classic recipe management, but on the **actual state of your freezer**:

- What is stored?
- In which container?
- How many portions?
- When should it be eaten?
- Which complete meals (sets) can be assembled from it?

Deliberately **pragmatic**:
- no frameworks
- no build step
- no cloud dependency (OpenAI optional)

---

## ✨ Core Features

### 🧾 Recipes
- Recipes with ingredients, preparation, and reheating notes  
- Flags: vegetarian / vegan  
- Calories per portion (manual or ChatGPT-estimated)  
- Recipes are **building blocks**, not fixed meals  

---

### 🍱 Sets (Meal-Prep Builder)
- A **Set** represents a real meal consisting of multiple boxes  
- Components:
  - from recipes **or**
  - free text (e.g. “potatoes”, “rice”, “vegetables”)  
- Components can be split across multiple boxes  
- Calories are calculated **per box**, correctly  

---

### 📦 Boxes & Containers
- Boxes receive **type-based codes** (`P001`, `B002`, `S001`, …)  
- One box may contain multiple components  
- Portion definition per box:
  - factor (e.g. `0.5`, `2.0`)
  - or text (`1 portion`, `250 g`)  
- Reusable containers **or**
- disposable containers (freezer bags / vacuum bags)  

---

### 🧊 Inventory
- Every packed box becomes an **inventory item**  
- Status tracking:
  - `IN_FREEZER`
  - `TAKEN_OUT`
  - `EATEN`
  - `DISCARDED`  
- FIFO logic  
- Expiration tracking and warnings  
- Full history is always preserved  

---

### 🌍 Internationalization (i18n)
- English / German  
- Language selectable via dropdown  
- Selection stored in a cookie  

---

## 🧱 Technical Overview

- **Backend:** PHP (Vanilla)
- **Frontend:** Vanilla JS + CSS
- **Database:** MySQL 8+
- **Containerization:** Docker + docker-compose
- **Architecture:**
  - Repository pattern
  - Feature-based controllers
  - Central router (`index.php`)
- **No frameworks, no build step**

Optional:
- **OpenAI API** for calorie estimation

---

## 🚀 Installation

### Requirements
- Docker
- Docker Compose

---

### 1) Clone the repository
```bash
git clone <REPO_URL>
cd FoodPrep-Recipes
