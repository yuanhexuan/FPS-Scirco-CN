import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import App from './App.jsx';
import LoginPage from './pages/LoginPage.jsx';
import LobbyPage from './pages/LobbyPage.jsx';
import RoomPage from './pages/RoomPage.jsx';
import GamePage from './pages/GamePage.jsx';
import './styles/index.css';

function PrivateRoute({ children }) {
  const token = localStorage.getItem('fps_token');
  return token ? children : <Navigate to="/login" replace />;
}

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/" element={<PrivateRoute><App /></PrivateRoute>}>
          <Route index element={<LobbyPage />} />
          <Route path="room/:roomId" element={<RoomPage />} />
          <Route path="play/:roomId" element={<GamePage />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  </React.StrictMode>
);
